<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FreshStockService
{
    public function totals(?string $through = null): array
    {
        $harvests = Harvest::query()->when($through, fn ($q) => $q->where('harvested_on', '<=', $through));
        $sales = Sale::query()->when($through, fn ($q) => $q->where('sold_on', '<=', $through));
        $packaging = PackagingRun::query()->when($through, fn ($q) => $q->where('packaged_on', '<=', $through));
        $invoiceSales = DB::table('invoice_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->whereNotNull('a.harvest_id')->when($through, fn ($q) => $q->where('i.sold_on', '<=', $through));
        $invoiceReturns = $this->returnedFresh()->when($through, fn ($q) => $q->where('r.returned_on', '<=', $through));
        $date = $through ?? today()->toDateString();
        $retailPackets = DB::table('finished_stock_movements as m')->join('packaging_runs as p', 'p.id', '=', DB::raw('COALESCE(m.packaging_run_id, m.source_packaging_run_id)'))
            ->join('products as product', 'product.id', '=', 'm.product_id')->where('product.is_active', true)->whereNull('product.deleted_at')
            ->where('p.pack_size_grams', Product::DEFAULT_PACK_SIZE_GRAMS)->where('m.moved_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('p.expires_on')->orWhere('p.expires_on', '>=', $date));
        $row = DB::query()->selectSub((clone $harvests)->selectRaw('COALESCE(SUM(gross_grams), 0)'), 'gross')
            ->selectSub((clone $harvests)->selectRaw('COALESCE(SUM(rejected_grams), 0)'), 'rejected')
            ->selectSub((clone $harvests)->selectRaw('COALESCE(SUM(saleable_grams), 0)'), 'saleable')
            ->selectSub($sales->selectRaw('COALESCE(SUM(weight_grams), 0)'), 'sold')
            ->selectSub($invoiceSales->selectRaw('COALESCE(SUM(a.quantity), 0)'), 'invoiced')
            ->selectSub($invoiceReturns->selectRaw('COALESCE(SUM(ra.quantity), 0)'), 'restored')
            ->selectSub($packaging->selectRaw('COALESCE(SUM(fresh_grams), 0)'), 'packaged')
            ->selectSub(DB::table('finished_stock_movements')->selectRaw('COALESCE(SUM(quantity), 0)'), 'finished')
            ->selectSub($retailPackets->selectRaw('COALESCE(SUM(m.quantity), 0)'), 'retail_packets')->first();

        $sold = (int) $row->sold + (int) $row->invoiced - (int) $row->restored;

        return ['gross' => (int) $row->gross, 'rejected' => (int) $row->rejected, 'saleable' => (int) $row->saleable, 'sold' => $sold, 'packaged' => (int) $row->packaged, 'finished' => (int) $row->finished, 'retail_packets' => (int) $row->retail_packets, 'available' => (int) $row->saleable - $sold - (int) $row->packaged];
    }

    public function assertSaleFits(string $weight, string $date): void
    {
        $grams = FreshQuantity::grams($weight);
        if ($grams > min($this->totals()['available'], $this->totals($date)['available'])) {
            throw ValidationException::withMessages(['weight' => 'There is not enough saleable harvested stock available for this sale date.']);
        }
    }

    public function assertTimeline(): void
    {
        $this->lots();
    }

    private function returnedFresh(): Builder
    {
        return DB::table('invoice_return_allocations as ra')->join('invoice_allocations as a', 'a.id', '=', 'ra.invoice_allocation_id')
            ->join('invoice_return_lines as rl', 'rl.id', '=', 'ra.invoice_return_line_id')->join('invoice_returns as r', 'r.id', '=', 'rl.invoice_return_id')
            ->whereNotNull('a.harvest_id')->where('rl.restock', true);
    }

    public function lots(?string $through = null): Collection
    {
        $harvests = Harvest::select('id', 'variety', 'harvested_on', 'saleable_grams', 'cost_per_kg', 'batch_id')->orderBy('harvested_on')->orderBy('id')->get()->keyBy('id');
        $events = $harvests->values()->map(fn ($h) => ['date' => $h->harvested_on, 'priority' => 0, 'id' => $h->id, 'grams' => (int) $h->saleable_grams, 'lot' => $h->id]);
        $events = $events->concat(PackagingRun::select('id', 'harvest_id', 'packaged_on', 'fresh_grams')->get()->map(fn ($p) => ['date' => $p->packaged_on, 'priority' => 1, 'id' => $p->id, 'grams' => -(int) $p->fresh_grams, 'lot' => $p->harvest_id]));
        $events = $events->concat(Sale::select('id', 'sold_on', 'weight_grams')->get()->map(fn ($s) => ['date' => $s->sold_on, 'priority' => 2, 'id' => $s->id, 'grams' => -(int) $s->weight_grams, 'lot' => null]));
        $events = $events->concat(DB::table('invoice_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->whereNotNull('a.harvest_id')->select('a.id', 'a.harvest_id', 'a.quantity', 'i.sold_on')->get()
            ->map(fn ($a) => ['date' => $a->sold_on, 'priority' => 2, 'id' => $a->id, 'grams' => -(int) $a->quantity, 'lot' => $a->harvest_id]));
        $events = $events->concat($this->returnedFresh()->select('ra.id', 'a.harvest_id', 'ra.quantity', 'r.returned_on')->get()
            ->map(fn ($a) => ['date' => $a->returned_on, 'priority' => 0, 'id' => $a->id, 'grams' => (int) $a->quantity, 'lot' => $a->harvest_id]));
        $balances = [];
        foreach ($events->sortBy([['date', 'asc'], ['priority', 'asc'], ['id', 'asc']]) as $event) {
            if ($through && $event['date'] > $through) {
                continue;
            }
            if ($event['lot'] !== null) {
                $id = $event['lot'];
                $balances[$id] = ($balances[$id] ?? 0) + $event['grams'];
                if ($balances[$id] < 0) {
                    throw ValidationException::withMessages(['weight' => 'The selected harvest has insufficient fresh stock on this date, including existing sales and packaging.']);
                }
            } else {
                // Legacy fresh sales have no variety/lot. Allocate them oldest-harvest first.
                $remaining = -$event['grams'];
                foreach ($balances as $id => $balance) {
                    $used = min($balance, $remaining);
                    $balances[$id] -= $used;
                    $remaining -= $used;
                    if ($remaining === 0) {
                        break;
                    }
                }
                if ($remaining > 0) {
                    throw ValidationException::withMessages(['weight' => 'This date would leave insufficient fresh stock for an existing sale.']);
                }
            }
        }

        return $harvests->each(function ($harvest) use ($balances) {
            $harvest->available_grams = $balances[$harvest->id] ?? 0;
        })->filter(fn ($harvest) => $harvest->available_grams > 0)->values();
    }
}
