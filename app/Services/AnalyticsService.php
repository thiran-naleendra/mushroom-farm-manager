<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AnalyticsService
{
    public const REPORTS = ['sales' => 'Sales revenue', 'harvests' => 'Harvest quantities', 'production' => 'Bag production', 'consumption' => 'Material consumption', 'stock' => 'Low stock & valuation', 'outstanding' => 'Grocery customer outstanding', 'damage' => 'Damaged & contaminated bags', 'costs' => 'Production cost per bag', 'products' => 'Profit by product', 'profits' => 'Gross & net profit trends'];

    public function build(User $user, Farm $farm, array $filter): array
    {
        $gate = Gate::forUser($user->fresh());
        $gate->authorize('view-report', $filter['report']);
        $financial = $gate->allows('view-financials');
        $from = $filter['from'];
        $to = $filter['to'];
        $length = ['daily' => 10, 'monthly' => 7, 'yearly' => 4][$filter['group']];
        $period = "SUBSTR(day, 1, $length)";
        $finance = app(FinancialService::class);
        $columns = [];
        $notes = [];
        $chart = [];
        switch ($filter['report']) {
            case 'sales':
                $query = DB::query()->fromSub($finance->revenueDays($farm), 'events')->whereBetween('sold_on', [$from, $to])->selectRaw("SUBSTR(sold_on, 1, $length) AS period, SUM(total) AS revenue")->groupByRaw("SUBSTR(sold_on, 1, $length)")->orderBy('period');
                $columns = ['period' => 'Period', 'revenue' => 'Net sales revenue'];
                $chart = ['revenue' => 'Sales revenue'];
                $notes[] = 'Revenue uses original sales less return credits on their dates; receipts and refunds are separate from revenue. Legacy loose sales have no recorded currency and retain their original amounts.';
                break;
            case 'harvests':
                $events = DB::table('harvests')->whereBetween('harvested_on', [$from, $to])->selectRaw("SUBSTR(harvested_on, 1, $length) AS period, variety, gross_grams, rejected_grams, saleable_grams");
                $query = DB::query()->fromSub($events, 'harvests')->select('period', 'variety')->selectRaw('SUM(gross_grams) / 1000.0 AS gross_kg, SUM(rejected_grams) / 1000.0 AS rejected_kg, SUM(saleable_grams) / 1000.0 AS saleable_kg')->groupBy('period', 'variety')->orderBy('period')->orderBy('variety');
                $columns = ['period' => 'Period', 'variety' => 'Variety', 'gross_kg' => 'Harvested kg', 'rejected_kg' => 'Rejected kg', 'saleable_kg' => 'Saleable kg'];
                $chart = ['saleable_kg' => 'Saleable kg', 'rejected_kg' => 'Rejected kg'];
                break;
            case 'production':
            case 'damage':
                $events = DB::table('batches')->where('production_status', '!=', 'voided')->whereBetween('started_on', [$from, $to])->selectRaw("SUBSTR(started_on, 1, $length) AS period, variety, bags, damaged_bags, contaminated_bags, discarded_bags");
                $query = DB::query()->fromSub($events, 'production')->select('period', 'variety')->selectRaw('SUM(bags) AS bags, SUM(damaged_bags) AS damaged, SUM(contaminated_bags) AS contaminated, SUM(discarded_bags) AS discarded')->groupBy('period', 'variety')->orderBy('period')->orderBy('variety');
                $columns = ['period' => 'Production period', 'variety' => 'Variety', 'bags' => 'Produced bags', 'damaged' => 'Damaged bags', 'contaminated' => 'Contaminated bags', 'discarded' => 'Discarded bags'];
                $chart = $filter['report'] === 'production' ? ['bags' => 'Produced bags'] : ['damaged' => 'Damaged', 'contaminated' => 'Contaminated', 'discarded' => 'Discarded'];
                $notes[] = 'Current bag dispositions for production started in this date range. These are not historical damage snapshots. Voided production is excluded.';
                break;
            case 'consumption':
                $events = DB::table('material_movements as m')->leftJoin('batches as b', 'b.id', '=', 'm.batch_id')->leftJoin('packaging_runs as p', 'p.id', '=', 'm.packaging_run_id')->whereIn('m.type', ['production', 'production_reverse', 'packaging'])
                    ->select('m.material_id', 'm.quantity_micros')->selectRaw("CASE WHEN m.type = 'production' THEN b.started_on WHEN m.type = 'packaging' THEN p.packaged_on ELSE DATE(m.created_at) END AS day");
                if ($financial) {
                    $events->addSelect('m.value_change');
                }
                $query = DB::query()->fromSub($events, 'e')->join('materials as m', 'm.id', '=', 'e.material_id')->join('material_units as u', 'u.id', '=', 'm.material_unit_id')->whereBetween('e.day', [$from, $to])->select('m.name', 'm.sku', 'm.supply_type', 'u.symbol')->selectRaw('-CAST(SUM(e.quantity_micros) AS DECIMAL(30,6)) / 1000000.0 AS quantity')->groupBy('m.id', 'm.name', 'm.sku', 'm.supply_type', 'u.symbol')->orderBy('m.name')->orderBy('m.id');
                $columns = ['name' => 'Material', 'sku' => 'SKU', 'supply_type' => 'Supply type', 'symbol' => 'Unit', 'quantity' => 'Net consumed'];
                if ($financial) {
                    $query->selectRaw('-SUM(e.value_change) AS recorded_cost');
                    $columns['recorded_cost'] = 'Recorded carrying cost';
                }
                $notes[] = 'Production and packaging consumption less audited reversals. Production/packaging dates apply to use; reversals use their posting date. Different material units are never added together. Material ledger currencies were not recorded; amounts are not converted.';
                break;
            case 'stock':
                $end = CarbonImmutable::parse($to)->addDay()->startOfDay();
                $ledger = DB::table('material_movements')->where('created_at', '<', $end)->select('material_id')->selectRaw('CAST(SUM(quantity_micros) AS DECIMAL(30,6)) / 1000000.0 AS quantity')->groupBy('material_id');
                if ($financial) {
                    $ledger->selectRaw('SUM(value_change) AS inventory_value');
                }
                $query = DB::table('materials as m')->leftJoinSub($ledger, 'stock', 'stock.material_id', '=', 'm.id')->join('material_units as u', 'u.id', '=', 'm.material_unit_id')->where('m.created_at', '<', $end)->select('m.name', 'm.sku', 'm.supply_type', 'u.symbol', 'm.minimum_stock')->selectRaw('COALESCE(stock.quantity, 0) AS quantity, CASE WHEN COALESCE(stock.quantity, 0) <= m.minimum_stock THEN 1 ELSE 0 END AS low_stock')->orderBy('m.name')->orderBy('m.id');
                if ($filter['low_stock'] ?? false) {
                    $query->whereRaw('COALESCE(stock.quantity, 0) <= m.minimum_stock');
                }
                $columns = ['name' => 'Material', 'sku' => 'SKU', 'supply_type' => 'Supply type', 'symbol' => 'Unit', 'quantity' => 'Stock quantity', 'minimum_stock' => 'Minimum stock', 'low_stock' => 'Low stock (1=yes)'];
                if ($financial) {
                    $query->selectRaw('COALESCE(stock.inventory_value, 0) AS inventory_value');
                    $columns['inventory_value'] = 'Recorded stock value';
                }
                $notes[] = 'Raw material ledger balances as of the end date; start date does not apply. Includes archived materials to preserve history. Thresholds and material names are current settings. Material ledger currencies were not recorded; valuations are original carrying amounts, not converted market values.';
                break;
            case 'outstanding':
                $balances = $finance->customerBalances($farm, $to);
                $query = DB::query()->fromSub($balances, 'b')->join('customers as c', 'c.id', '=', 'b.customer_id')->where('c.type', 'grocery')->where('b.balance', '>', 0)->select('c.name', 'c.phone', 'c.email', 'b.balance')->orderByDesc('b.balance')->orderBy('c.id');
                $columns = ['name' => 'Grocery customer', 'phone' => 'Phone', 'email' => 'Email', 'balance' => 'Outstanding'];
                $notes[] = 'Positive customer ledger balances as of the end date using sale, receipt and return dates; start date does not apply. Includes inactive and archived accounts. Current customer type is used.';
                break;
            case 'costs':
                $query = DB::table('batches')->where('production_status', '!=', 'voided')->whereBetween('started_on', [$from, $to])->where(fn ($q) => $q->where('currency', $farm->currency)->orWhereNull('currency'))->select('id', 'variety', 'started_on', 'bags', 'total_cost', 'cost_per_bag')->orderBy('started_on')->orderBy('id');
                $columns = ['id' => 'Production ID', 'variety' => 'Variety', 'started_on' => 'Production date', 'bags' => 'Produced bags', 'total_cost' => 'Original production cost', 'cost_per_bag' => 'Original cost / bag'];
                $notes[] = 'Original saved production costs; unknown historical costs stay unknown. Cost transfers to harvests do not add expenses.';
                break;
            case 'products':
                $query = $this->productProfit($farm, $from, $to);
                $columns = ['product_name' => 'Product at sale', 'sku' => 'SKU', 'type' => 'Type', 'unit' => 'Selling unit', 'pack_size_grams' => 'Pack grams', 'quantity' => 'Net sold', 'revenue' => 'Net revenue', 'cogs' => 'Known COGS', 'gross_profit' => 'Estimated gross profit', 'unknown_costs' => 'Unknown cost events'];
                $notes[] = 'Saved invoice prices and stock costs, grouped by historical product details. Return credits reduce revenue; only saleable restocks reverse COGS. Operating expenses are not allocated to products. Legacy loose sales without product links are excluded. Unknown costs are excluded, so profit is an estimate.';
                break;
            case 'profits':
                $query = $this->profitTrends($farm, $from, $to, $period);
                $columns = ['period' => 'Period', 'revenue' => 'Net revenue', 'cogs' => 'Known COGS', 'expenses' => 'Operating expenses', 'gross_profit' => 'Estimated gross profit', 'net_profit' => 'Estimated net profit', 'unknown_costs' => 'Unknown cost events'];
                $chart = ['gross_profit' => 'Estimated gross profit', 'net_profit' => 'Estimated net profit'];
                $notes[] = 'Profit is an estimate where costs are missing or manually valued. Material purchases and production payments already capitalized are not operating expenses. Cash receipts are not revenue. Legacy sales retain their original amounts without currency conversion.';
                break;
        }

        return compact('query', 'columns', 'notes', 'chart', 'financial') + ['title' => self::REPORTS[$filter['report']]];
    }

    private function productProfit(Farm $farm, string $from, string $to): Builder
    {
        $fields = ['l.product_id', 'l.product_name', 'l.sku', 'l.type', 'l.unit', 'l.pack_size_grams'];
        $sales = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.currency', $farm->currency)->select($fields)->selectRaw('i.sold_on AS day, l.quantity AS quantity, l.total AS revenue, 0 AS cogs, 0 AS unknown_costs');
        $returns = DB::table('invoice_return_lines as r')->join('invoice_returns as h', 'h.id', '=', 'r.invoice_return_id')->join('invoices as i', 'i.id', '=', 'h.invoice_id')->join('invoice_lines as l', 'l.id', '=', 'r.invoice_line_id')->where('i.currency', $farm->currency)->select($fields)->selectRaw('h.returned_on AS day, -r.quantity AS quantity, -r.credit AS revenue, 0 AS cogs, 0 AS unknown_costs');
        $costs = DB::query()->fromSub(app(FinancialService::class)->costDays($farm), 'c')->join('invoice_lines as l', 'l.id', '=', 'c.invoice_line_id')->select($fields)->selectRaw('c.day, 0 AS quantity, 0 AS revenue, COALESCE(c.cost, 0) AS cogs, CASE WHEN c.cost IS NULL THEN 1 ELSE 0 END AS unknown_costs');

        return DB::query()->fromSub($sales->unionAll($returns)->unionAll($costs), 'events')->whereBetween('day', [$from, $to])->select('product_id', 'product_name', 'sku', 'type', 'unit', 'pack_size_grams')->selectRaw('SUM(quantity) AS quantity, SUM(revenue) AS revenue, SUM(cogs) AS cogs, SUM(revenue) - SUM(cogs) AS gross_profit, SUM(unknown_costs) AS unknown_costs')->groupBy('product_id', 'product_name', 'sku', 'type', 'unit', 'pack_size_grams')->orderBy('product_name')->orderBy('product_id');
    }

    private function profitTrends(Farm $farm, string $from, string $to, string $period): Builder
    {
        $finance = app(FinancialService::class);
        $revenue = DB::query()->fromSub($finance->revenueDays($farm), 'r')->selectRaw('sold_on AS day, total AS revenue, 0 AS cogs, 0 AS expenses, 0 AS unknown_costs');
        $costs = DB::query()->fromSub($finance->costDays($farm), 'c')->selectRaw('day, 0 AS revenue, COALESCE(cost, 0) AS cogs, 0 AS expenses, CASE WHEN cost IS NULL THEN 1 ELSE 0 END AS unknown_costs');
        $expenses = DB::table('expenses')->whereNull('deleted_at')->where('treatment', 'operating')->where(fn ($q) => $q->where('currency', $farm->currency)->orWhereNull('currency'))->selectRaw('spent_on AS day, 0 AS revenue, 0 AS cogs, amount AS expenses, 0 AS unknown_costs');
        $legacy = DB::table('sales')->selectRaw('sold_on AS day, 0 AS revenue, 0 AS cogs, 0 AS expenses, 1 AS unknown_costs');
        $events = DB::query()->fromSub($revenue->unionAll($costs)->unionAll($expenses)->unionAll($legacy), 'events')->whereBetween('day', [$from, $to])->selectRaw("$period AS period, revenue, cogs, expenses, unknown_costs");

        return DB::query()->fromSub($events, 'periods')->select('period')->selectRaw('SUM(revenue) AS revenue, SUM(cogs) AS cogs, SUM(expenses) AS expenses, SUM(revenue) - SUM(cogs) AS gross_profit, SUM(revenue) - SUM(cogs) - SUM(expenses) AS net_profit, SUM(unknown_costs) AS unknown_costs')->groupBy('period')->orderBy('period');
    }
}
