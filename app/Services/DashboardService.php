<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardService
{
    public function data(User $user, Farm $farm): array
    {
        $gate = Gate::forUser($user);
        $production = $gate->allows('access-section', 'production');
        $harvests = $gate->allows('access-section', 'harvests');
        $inventory = $gate->allows('access-section', 'inventory');
        $sales = $gate->allows('access-section', 'sales');
        $financial = $gate->allows('view-financials');
        $today = CarbonImmutable::today($farm->timezone);
        $month = $today->startOfMonth();
        $end = $month->addMonth();
        $activity = collect();
        $summary = collect();
        $reminders = collect();
        $metrics = [];
        $materialAlerts = $inventory ? Material::with('unit')->select('id', 'name', 'quantity', 'minimum_stock', 'material_unit_id')
            ->where('is_active', true)->whereColumn('quantity', '<=', 'minimum_stock')->orderBy('name')->limit(5)->get() : collect();

        if ($production) {
            $summary = Batch::where('production_status', '!=', 'voided')->select('stage')->selectRaw('COUNT(*) AS grows, COALESCE(SUM(bags), 0) AS bags, COALESCE(SUM(contaminated_bags), 0) AS contaminated, COALESCE(SUM(sold_bags), 0) AS sold, COALESCE(SUM(damaged_bags), 0) AS damaged, COALESCE(SUM(discarded_bags), 0) AS discarded')->groupBy('stage')->get();
            $metrics['active_bags'] = $summary->where('stage', '!=', 'completed')->sum(fn ($row) => $row->bags - $row->contaminated - $row->sold - $row->damaged - $row->discarded);
            $metrics['produced_bags'] = $summary->sum('bags');
            $metrics['contaminated_bags'] = $summary->sum('contaminated');
            $activity = $activity->concat(Batch::select('id', 'variety', 'updated_at')->orderByDesc('updated_at')->orderByDesc('id')->limit(8)->get()->map(fn ($row) => [
                'label' => 'Production · '.$row->variety, 'detail' => 'Production record updated', 'at' => $row->updated_at, 'section' => 'production',
            ]));
        }
        if ($production || $harvests) {
            $reminders = Batch::select('id', 'variety', 'location', 'expected_harvest_on', 'bags', 'contaminated_bags', 'sold_bags', 'damaged_bags', 'discarded_bags')
                ->where('stage', '!=', 'completed')->where('production_status', '!=', 'voided')->whereRaw('bags > contaminated_bags + sold_bags + damaged_bags + discarded_bags')
                ->whereBetween('expected_harvest_on', [$today->toDateString(), $today->addDays(14)->toDateString()])
                ->orderBy('expected_harvest_on')->orderBy('id')->limit(6)->get();
        }
        if ($harvests || $inventory) {
            $stock = app(FreshStockService::class)->totals();
            $metrics['harvested_kg'] = FreshQuantity::kg($stock['gross']);
            if ($inventory) {
                $metrics['fresh_kg'] = FreshQuantity::kg($stock['available']);
                $metrics['packaged_stock'] = $stock['finished'];
                $metrics['retail_packets'] = $stock['retail_packets'];
            }
        }
        if ($harvests) {
            $activity = $activity->concat(Harvest::select('id', 'weight', 'created_at', 'variety')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()->map(fn ($row) => [
                'label' => 'Harvest · '.$row->variety, 'detail' => number_format($row->weight, 3).' kg saleable recorded', 'at' => $row->created_at, 'section' => 'harvests',
            ]));
        }
        if ($sales) {
            $activity = $activity->concat(Sale::select('id', 'customer', 'weight', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()->map(fn ($row) => [
                'label' => 'Sale · '.$row->customer, 'detail' => number_format($row->weight, 3).' kg sold', 'at' => $row->created_at, 'section' => 'sales',
            ]));
            $activity = $activity->concat(Invoice::select('id', 'number', 'customer_snapshot', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()->map(fn ($row) => [
                'label' => 'Invoice · '.$row->customer_snapshot['name'], 'detail' => $row->number, 'at' => $row->created_at, 'section' => 'sales',
            ]));
        }
        $chart = [];
        if ($financial) {
            $revenue = $this->revenueDays($farm);
            $balances = DB::table('customer_account_entries')->where('currency', $farm->currency)->select('customer_id')->selectRaw('SUM(amount) AS balance')->groupBy('customer_id');
            $financials = DB::query()->selectSub(DB::query()->fromSub(clone $revenue, 'revenue')->where('sold_on', '>=', $month->toDateString())->where('sold_on', '<', $end->toDateString())->selectRaw('COALESCE(SUM(total), 0)'), 'revenue')
                ->selectSub(Expense::where('treatment', 'operating')->where(fn ($q) => $q->where('currency', $farm->currency)->orWhereNull('currency'))->where('spent_on', '>=', $month->toDateString())->where('spent_on', '<', $end->toDateString())->selectRaw('COALESCE(SUM(amount), 0)'), 'expenses')
                ->selectSub(DB::query()->fromSub(app(FinancialService::class)->costDays($farm), 'costs')->where('day', '>=', $month->toDateString())->where('day', '<', $end->toDateString())->selectRaw('COALESCE(SUM(cost), 0)'), 'cogs')
                ->selectSub(DB::query()->fromSub($balances, 'balances')->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0)'), 'outstanding')->first();
            $metrics['revenue'] = round((float) $financials->revenue, 2);
            $metrics['expenses'] = $financials->expenses;
            $metrics['profit'] = (string) PurchaseAmounts::money($financials->revenue)->minus(PurchaseAmounts::money($financials->cogs))->minus(PurchaseAmounts::money($financials->expenses));
            $metrics['outstanding'] = $financials->outstanding;
            $totals = DB::query()->fromSub($revenue, 'revenue')->where('sold_on', '>=', $month->subMonths(5)->toDateString())->where('sold_on', '<', $end->toDateString())
                ->select('sold_on')->selectRaw('SUM(total) AS total')->groupBy('sold_on')->orderBy('sold_on')->get()
                ->groupBy(fn ($row) => substr($row->sold_on, 0, 7))->map(fn ($days) => round($days->sum('total'), 2));
            for ($offset = 5; $offset >= 0; $offset--) {
                $date = $month->subMonths($offset);
                $chart[] = ['label' => $date->format('M Y'), 'total' => $totals->get($date->format('Y-m'), 0)];
            }
            $activity = $activity->concat(Expense::select('id', 'description', 'amount', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()->map(fn ($row) => [
                'label' => 'Expense · '.$row->description, 'detail' => $farm->currency.' '.number_format($row->amount, 2), 'at' => $row->created_at, 'section' => 'expenses',
            ]));
        }

        return [
            'metrics' => $metrics, 'summary' => $summary, 'reminders' => $reminders,
            'materialAlerts' => $materialAlerts,
            'activities' => $activity->sortByDesc('at')->take(8)->values(), 'chart' => $chart,
            'monthLabel' => $month->format('F Y'), 'financial' => $financial,
            'lowStock' => $inventory && $farm->fresh_stock_alert_kg !== null && $metrics['fresh_kg'] <= $farm->fresh_stock_alert_kg,
        ];
    }

    private function revenueDays(Farm $farm): Builder
    {
        return app(FinancialService::class)->revenueDays($farm);
    }
}
