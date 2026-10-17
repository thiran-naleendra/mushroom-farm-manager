<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\FinishedStockMovement;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturnAllocation;
use App\Models\InvoiceReturnLine;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function save(User $actor, array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($actor, $data, $product) {
            Farm::lockForUpdate()->firstOrFail();
            $product = $product ? Product::lockForUpdate()->findOrFail($product->id) : new Product;
            $gate = Gate::forUser($actor->fresh());
            $product->exists ? $gate->authorize('update', $product) : $gate->authorize('create', Product::class);
            $data['type'] ??= $product->type ?? 'packaged';
            $data['unit'] ??= $data['type'] === 'cultivation_bag' ? 'bag' : ($data['type'] === 'fresh' ? 'kg' : 'g');
            $data['pack_size_grams'] = $data['type'] === 'packaged' ? (int) $data['pack_size_grams'] : null;
            $data['weight'] = $data['type'] === 'packaged' ? (string) $data['pack_size_grams'] : ($data['type'] === 'fresh' ? ($data['weight'] ?? '1') : null);
            if ($product->exists && ($product->movements()->exists() || InvoiceLine::where('product_id', $product->id)->exists()) && ($product->type !== $data['type'] || $product->unit !== $data['unit'] || $product->variety !== $data['variety'] || $product->pack_size_grams !== $data['pack_size_grams'])) {
                throw ValidationException::withMessages(['pack_size_grams' => 'A stocked product must keep its variety and size. Create another product for a new size or variety.']);
            }
            $product->fill($data)->save();

            return $product;
        });
    }

    public function stockQuantity(string $type, string $unit, string $quantity): int
    {
        try {
            return BigDecimal::of($quantity)->multipliedBy($type === 'fresh' && $unit === 'kg' ? 1000 : 1)->toScale(0)->toInt();
        } catch (RoundingNecessaryException) {
            throw ValidationException::withMessages(['lines' => 'Use whole packets/bags and fresh weights measurable in whole grams.']);
        }
    }

    public function allocate(User $actor, InvoiceLine $line, string $date): void
    {
        $currency = Farm::firstOrFail()->currency;
        $remaining = $line->stock_quantity;
        $sources = match ($line->type) {
            'fresh' => app(FreshStockService::class)->lots($date)->where('variety', $line->variety),
            'packaged' => PackagingRun::where('product_id', $line->product_id)->where('packaged_on', '<=', $date)->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $date))->orderBy('packaged_on')->orderBy('id')->lockForUpdate()->get(),
            default => Batch::where('variety', $line->variety)->where('production_status', '!=', 'voided')->where('stage', '!=', 'completed')->where('started_on', '<=', $date)->orderBy('started_on')->orderBy('id')->lockForUpdate()->get(),
        };
        foreach ($sources as $source) {
            $available = match ($line->type) {
                'fresh' => $source->available_grams,
                'packaged' => (int) $this->packetMovements($source->id)->where('moved_on', '<=', $date)->sum('quantity'),
                default => $source->availableBags(),
            };
            $used = min($remaining, max(0, $available));
            if ($used === 0) {
                continue;
            }
            $sourceCurrency = $line->type === 'fresh' ? ($source->cost_per_kg !== null ? Batch::whereKey($source->batch_id)->value('currency') : null) : $source->currency;
            if ($sourceCurrency !== null && $sourceCurrency !== $currency) {
                throw ValidationException::withMessages(['lines' => 'Stock costs use another currency. Currency conversion is required before sale.']);
            }
            $allocation = $line->allocations()->create([
                'harvest_id' => $line->type === 'fresh' ? $source->id : null, 'batch_id' => $line->type === 'cultivation_bag' ? $source->id : null,
                'packaging_run_id' => $line->type === 'packaged' ? $source->id : null, 'quantity' => $used,
                'unit_cost' => $line->type === 'packaged' ? $source->cost_per_packet : ($line->type === 'cultivation_bag' ? app(FinancialService::class)->bagUnitCost($source) : $source->cost_per_kg),
            ]);
            if ($line->type === 'packaged') {
                FinishedStockMovement::create(['product_id' => $line->product_id, 'source_packaging_run_id' => $source->id, 'invoice_line_id' => $line->id, 'quantity' => -$used, 'unit_cost' => $allocation->unit_cost, 'moved_on' => $date]);
                $this->assertPacketTimeline($source->id);
            } elseif ($line->type === 'cultivation_bag') {
                $before = $source->sold_bags;
                $source->sold_bags += $used;
                $source->save();
                $source->audits()->create(['user_id' => $actor->id, 'action' => 'sale', 'reason' => 'Invoice #'.$line->invoice_id, 'before' => ['sold_bags' => $before], 'after' => ['sold_bags' => $source->sold_bags]]);
            }
            $remaining -= $used;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages(['lines' => 'Insufficient available stock for '.$line->product_name.'.']);
        }
        app(FreshStockService::class)->assertTimeline();
    }

    private function packetMovements(int $source): Builder
    {
        return FinishedStockMovement::where(fn ($q) => $q->where('packaging_run_id', $source)->orWhere('source_packaging_run_id', $source));
    }

    private function assertPacketTimeline(int $source): void
    {
        $balance = 0;
        foreach ($this->packetMovements($source)->select('moved_on')->selectRaw('SUM(quantity) AS packets')->groupBy('moved_on')->orderBy('moved_on')->get() as $day) {
            $balance += (int) $day->packets;
            if ($balance < 0) {
                throw ValidationException::withMessages(['lines' => 'This date would overdraw finished stock needed by an existing invoice.']);
            }
        }
    }

    public function restore(User $actor, InvoiceLine $line, InvoiceReturnLine $return, string $date): void
    {
        if ($return->restock) {
            $product = Product::withTrashed()->lockForUpdate()->findOrFail($line->product_id);
            if ($product->trashed()) {
                $product->restore();
                $product->is_active = false;
                $product->save();
            }
        }
        $remaining = $return->stock_quantity;
        foreach ($line->allocations()->orderBy('id')->get() as $allocation) {
            $usedBefore = (int) InvoiceReturnAllocation::where('invoice_allocation_id', $allocation->id)->sum('quantity');
            $quantity = min($remaining, $allocation->quantity - $usedBefore);
            if ($quantity <= 0) {
                continue;
            }
            InvoiceReturnAllocation::create(['invoice_return_line_id' => $return->id, 'invoice_allocation_id' => $allocation->id, 'quantity' => $quantity]);
            if ($return->restock && $allocation->packaging_run_id) {
                FinishedStockMovement::create(['product_id' => $line->product_id, 'source_packaging_run_id' => $allocation->packaging_run_id, 'invoice_line_id' => $line->id, 'invoice_return_line_id' => $return->id, 'quantity' => $quantity, 'unit_cost' => $allocation->unit_cost, 'moved_on' => $date]);
            } elseif ($return->restock && $allocation->batch_id) {
                $batch = Batch::lockForUpdate()->findOrFail($allocation->batch_id);
                if ($batch->sold_bags < $quantity) {
                    throw ValidationException::withMessages(['lines' => 'The production sold-bag counter does not match its invoice history.']);
                }
                $before = $batch->sold_bags;
                $batch->sold_bags -= $quantity;
                $batch->save();
                $batch->audits()->create(['user_id' => $actor->id, 'action' => 'sale_return', 'reason' => 'Return #'.$return->invoice_return_id, 'before' => ['sold_bags' => $before], 'after' => ['sold_bags' => $batch->sold_bags]]);
            }
            $remaining -= $quantity;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining !== 0) {
            throw ValidationException::withMessages(['lines' => 'Returned stock exceeds the original stock allocation.']);
        }
        app(FreshStockService::class)->assertTimeline();
    }

    public function stockLevels(Collection $products): array
    {
        $fresh = $products->contains('type', 'fresh') ? app(FreshStockService::class)->lots()->groupBy('variety')->map(fn ($lots) => $lots->sum('available_grams')) : collect();
        $bags = $products->contains('type', 'cultivation_bag') ? Batch::where('production_status', '!=', 'voided')->where('stage', '!=', 'completed')
            ->select('variety')->selectRaw('SUM(bags - sold_bags - damaged_bags - contaminated_bags - discarded_bags) as available')->groupBy('variety')->pluck('available', 'variety') : collect();

        return $products->mapWithKeys(fn ($product) => [$product->id => match ($product->type) {
            'fresh' => ['quantity' => $product->unit === 'g' ? (string) $fresh->get($product->variety, 0) : FreshQuantity::kg((int) $fresh->get($product->variety, 0)), 'unit' => $product->unit],
            'cultivation_bag' => ['quantity' => (string) $bags->get($product->variety, 0), 'unit' => 'bags'],
            default => ['quantity' => (string) ($product->stock ?? $product->movements()->sum('quantity')), 'unit' => 'packets'],
        }])->all();
    }

    public function archive(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product) {
            Farm::lockForUpdate()->firstOrFail();
            $product = Product::lockForUpdate()->findOrFail($product->id);
            Gate::forUser($actor->fresh())->authorize('delete', $product);
            if ((int) $product->movements()->sum('quantity') !== 0) {
                throw ValidationException::withMessages(['product' => 'Deactivate products with remaining finished stock instead of archiving them.']);
            }
            $product->delete();
        });
    }
}
