<?php

namespace App\Services;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PurchaseReportService
{
    public function query(): Builder
    {
        $query = Purchase::withSum(['payments as paid_sum' => fn ($q) => $q->where('type', 'payment')], 'amount')
            ->withSum(['payments as refunds_sum' => fn ($q) => $q->where('type', 'refund')], 'amount')
            ->withSum('returns as credits_sum', 'credit');

        return Purchase::fromSub($query, 'purchases');
    }

    public function supplierTotals(int $supplier): array
    {
        $totals = DB::query()->fromSub($this->query()->where('supplier_id', $supplier), 'report')
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(credits_sum), 0) AS credits, COALESCE(SUM(paid_sum), 0) AS paid, COALESCE(SUM(refunds_sum), 0) AS refunds')->first();

        return ['orders' => $totals->orders, 'total' => $totals->total] + PurchaseAmounts::fromTotals($totals->total, $totals->credits, $totals->paid, $totals->refunds);
    }
}
