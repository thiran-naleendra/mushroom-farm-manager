<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FinancialService
{
    public function allocateHarvest(User $user, Harvest $harvest, string $amount): void
    {
        Gate::forUser($user->fresh())->authorize('view-financials');
        DB::transaction(function () use ($user, $harvest, $amount) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            $harvest = Harvest::lockForUpdate()->findOrFail($harvest->id);
            $batch = Batch::lockForUpdate()->find($harvest->batch_id);
            if (! $batch || $batch->production_status !== 'confirmed' || $batch->currency !== $farm->currency || $harvest->allocated_cost !== null || $harvest->saleable_grams <= 0
                || DB::table('invoice_allocations')->where('batch_id', $batch->id)->exists()
                || DB::table('invoice_allocations')->where('harvest_id', $harvest->id)->exists()
                || PackagingRun::where('harvest_id', $harvest->id)->exists()
                || DB::table('sales')->where('sold_on', '>=', $harvest->harvested_on)->exists()) {
                throw ValidationException::withMessages(['amount' => 'Allocate once to saleable harvest from confirmed production before any related sales or packaging. Legacy sales prevent retrospective allocation.']);
            }
            $used = BigDecimal::zero();
            foreach (Harvest::where('batch_id', $batch->id)->whereNotNull('allocated_cost')->pluck('allocated_cost') as $cost) {
                $used = $used->plus($cost);
            }
            if (BigDecimal::of($amount)->isNegative() || $used->plus($amount)->isGreaterThan($batch->total_cost)) {
                throw ValidationException::withMessages(['amount' => 'Harvest allocations cannot exceed the original production cost.']);
            }
            DB::table('harvests')->where('id', $harvest->id)->update(['allocated_cost' => $amount, 'cost_per_kg' => BigDecimal::of($amount)->multipliedBy(1000)->dividedBy($harvest->saleable_grams, 6, RoundingMode::HalfUp)->__toString(), 'valued_by' => $user->id, 'valued_at' => now()]);
            $batch->audits()->create(['user_id' => $user->id, 'action' => 'harvest_cost_allocation', 'reason' => 'Transfer production cost to harvest #'.$harvest->id, 'before' => ['allocated_cost' => null], 'after' => ['harvest_id' => $harvest->id, 'allocated_cost' => $amount]]);
        });
    }

    public function bagUnitCost(Batch $batch): ?string
    {
        if ($batch->total_cost === null) {
            return $batch->cost_per_bag;
        }
        if ($batch->cost_per_bag === null || ! $batch->bags) {
            return null;
        }
        $remaining = BigDecimal::of($batch->total_cost);
        foreach (Harvest::where('batch_id', $batch->id)->whereNotNull('allocated_cost')->pluck('allocated_cost') as $cost) {
            $remaining = $remaining->minus($cost);
        }

        return (string) $remaining->dividedBy($batch->bags, 6, RoundingMode::HalfUp);
    }

    public function revenueDays(Farm $farm): Builder
    {
        return DB::table('sales')->select('sold_on')->selectRaw('weight * price_per_kg AS total')
            ->unionAll(DB::table('invoices')->where('currency', $farm->currency)->select('sold_on', 'total'))
            ->unionAll(DB::table('invoice_returns as r')->join('invoices as i', 'i.id', '=', 'r.invoice_id')->where('i.currency', $farm->currency)->selectRaw('r.returned_on AS sold_on, -r.credit AS total'));
    }

    public function costDays(Farm $farm, ?int $packSize = null): Builder
    {
        // Allocation quantities are grams for fresh mushrooms and counts otherwise.
        $sales = DB::table('invoice_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.currency', $farm->currency)
            ->selectRaw('i.sold_on AS day, a.quantity * a.unit_cost / CASE WHEN l.type = ? THEN 1000.0 ELSE 1 END AS cost', ['fresh']);
        $returns = DB::table('invoice_return_allocations as ra')->join('invoice_allocations as a', 'a.id', '=', 'ra.invoice_allocation_id')->join('invoice_return_lines as rl', 'rl.id', '=', 'ra.invoice_return_line_id')->join('invoice_returns as r', 'r.id', '=', 'rl.invoice_return_id')->join('invoices as i', 'i.id', '=', 'r.invoice_id')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')->where('i.currency', $farm->currency)->where('rl.restock', true)
            ->selectRaw('r.returned_on AS day, -ra.quantity * a.unit_cost / CASE WHEN l.type = ? THEN 1000.0 ELSE 1 END AS cost', ['fresh']);
        if ($packSize !== null) {
            $sales->where('l.type', 'packaged')->where('l.pack_size_grams', $packSize);
            $returns->where('l.type', 'packaged')->where('l.pack_size_grams', $packSize);
        }

        return $sales->addSelect('l.id as invoice_line_id')->unionAll($returns->addSelect('l.id as invoice_line_id'));
    }

    public function report(Farm $farm, string $from, string $to): array
    {
        $revenue = PurchaseAmounts::money(DB::query()->fromSub($this->revenueDays($farm), 'revenue')->whereBetween('sold_on', [$from, $to])->sum('total'));
        $cost = PurchaseAmounts::money(DB::query()->fromSub($this->costDays($farm), 'costs')->whereBetween('day', [$from, $to])->sum('cost'));
        $expenses = Expense::whereBetween('spent_on', [$from, $to])->where(fn ($q) => $q->where('currency', $farm->currency)->orWhereNull('currency'));
        $operating = PurchaseAmounts::money((clone $expenses)->where('treatment', 'operating')->sum('amount'));
        $payments = PurchaseAmounts::money((clone $expenses)->sum('amount'));
        $balances = $this->customerBalances($farm, $to);
        $outstanding = PurchaseAmounts::money(DB::query()->fromSub($balances, 'balances')->where('balance', '>', 0)->sum('balance'));
        $receipts = DB::table('invoice_payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')->where('i.currency', $farm->currency)->whereBetween('p.paid_on', [$from, $to])->selectRaw("COALESCE(SUM(CASE WHEN p.type = 'payment' THEN p.amount ELSE -p.amount END), 0) AS total")->first();
        $unknown = DB::table('invoice_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.currency', $farm->currency)->whereBetween('i.sold_on', [$from, $to])->whereNull('a.unit_cost')->count();
        $unknown += DB::table('sales')->whereBetween('sold_on', [$from, $to])->count();

        return [
            'revenue' => (string) $revenue, 'cogs' => (string) $cost, 'gross_profit' => (string) $revenue->minus($cost),
            'operating_expenses' => (string) $operating, 'net_profit' => (string) $revenue->minus($cost)->minus($operating),
            'outstanding' => (string) $outstanding, 'receipts' => (string) PurchaseAmounts::money($receipts->total),
            'expense_payments' => (string) $payments, 'unknown_costs' => $unknown,
            'retail_packets' => $this->packetProfit($farm, $from, $to),
            'daily' => (clone $expenses)->select('spent_on')->selectRaw('SUM(amount) AS total')->groupBy('spent_on')->orderBy('spent_on')->get(),
            'monthly' => (clone $expenses)->selectRaw('SUBSTR(spent_on, 1, 7) AS month, SUM(amount) AS total')->groupByRaw('SUBSTR(spent_on, 1, 7)')->orderBy('month')->get()->mapWithKeys(fn ($row) => [$row->month => (string) PurchaseAmounts::money($row->total)]),
            'production' => Batch::where('production_status', 'confirmed')->where('currency', $farm->currency)->whereBetween('started_on', [$from, $to])->select('id', 'variety', 'bags', 'total_cost', 'cost_per_bag')->orderByDesc('id')->paginate(15, ['*'], 'production_page')->withQueryString(),
            'packaging' => PackagingRun::where('currency', $farm->currency)->whereBetween('packaged_on', [$from, $to])->orderByDesc('id')->paginate(15, ['*'], 'packaging_page')->withQueryString(),
            'unvalued_harvests' => Harvest::whereNull('allocated_cost')->whereNotNull('batch_id')->where('saleable_grams', '>', 0)->orderByDesc('id')->paginate(15, ['*'], 'harvest_page')->withQueryString(),
        ];
    }

    public function packetProfit(Farm $farm, string $from, string $to): array
    {
        $size = Product::DEFAULT_PACK_SIZE_GRAMS;
        $sales = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.currency', $farm->currency)->where('l.type', 'packaged')->where('l.pack_size_grams', $size)
            ->selectRaw('i.sold_on AS day, l.quantity AS quantity, l.total AS revenue');
        $returns = DB::table('invoice_return_lines as rl')->join('invoice_returns as r', 'r.id', '=', 'rl.invoice_return_id')->join('invoices as i', 'i.id', '=', 'r.invoice_id')->join('invoice_lines as l', 'l.id', '=', 'rl.invoice_line_id')->where('i.currency', $farm->currency)->where('l.type', 'packaged')->where('l.pack_size_grams', $size)
            ->selectRaw('r.returned_on AS day, -rl.quantity AS quantity, -rl.credit AS revenue');
        $row = DB::query()->fromSub($sales->unionAll($returns), 'packets')->whereBetween('day', [$from, $to])->selectRaw('COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(revenue), 0) AS revenue')->first();
        $cost = PurchaseAmounts::money(DB::query()->fromSub($this->costDays($farm, $size), 'costs')->whereBetween('day', [$from, $to])->sum('cost'));
        $quantity = (int) $row->quantity;
        $profit = PurchaseAmounts::money($row->revenue)->minus($cost);

        return ['quantity' => $quantity, 'cost_per_packet' => $quantity > 0 ? (string) $cost->dividedBy($quantity, 2, RoundingMode::HalfUp) : null,
            'profit_per_packet' => $quantity > 0 ? (string) $profit->dividedBy($quantity, 2, RoundingMode::HalfUp) : null];
    }

    public function productionReport(Farm $farm, array $filters): array
    {
        $query = Batch::where('production_status', '!=', 'voided')->where(fn ($q) => $q->where('currency', $farm->currency)->orWhereNull('currency'));
        foreach (['batch_id' => 'id', 'location' => 'location', 'stage' => 'stage'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('started_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('started_on', '<=', $filters['to']);
        }
        $locationRows = (clone $query)->select('bags', 'total_cost')->selectRaw("COALESCE(NULLIF(location, ''), '') AS recorded_location");
        $locations = DB::query()->fromSub($locationRows, 'production_locations')->select('recorded_location')
            ->selectRaw('COUNT(*) AS productions, SUM(bags) AS bags, COALESCE(SUM(total_cost), 0) AS recorded_cost, SUM(CASE WHEN total_cost IS NULL THEN 1 ELSE 0 END) AS unknown_costs')
            ->groupBy('recorded_location')->orderBy('recorded_location')->get();
        $batches = $query->withCount('harvests')->withSum('harvests', 'weight')->withSum('harvests', 'rejected_weight')->withSum('harvests', 'allocated_cost')->orderByDesc('id')->paginate(15)->withQueryString();

        return ['batches' => $batches, 'locationTotals' => $locations];
    }

    public function customerBalances(Farm $farm, string $through): Builder
    {
        return DB::table('customer_account_entries as e')->leftJoin('invoice_payments as p', 'p.id', '=', 'e.invoice_payment_id')->leftJoin('invoice_returns as r', 'r.id', '=', 'e.invoice_return_id')->leftJoin('invoices as i', 'i.id', '=', 'e.invoice_id')->leftJoin('sales as s', 's.id', '=', 'e.sale_id')
            ->where('e.currency', $farm->currency)->whereRaw('DATE(COALESCE(p.paid_on, r.returned_on, i.sold_on, s.sold_on, e.created_at)) <= ?', [$through])->select('e.customer_id')->selectRaw('SUM(e.amount) AS balance')->groupBy('e.customer_id');
    }
}
