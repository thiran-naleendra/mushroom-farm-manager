<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\FinishedStockMovement;
use App\Models\Harvest;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PackagingService
{
    public function __construct(private FreshStockService $fresh, private MaterialStockService $materials) {}

    public function record(User $actor, array $data): PackagingRun
    {
        return DB::transaction(function () use ($actor, $data) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('access-section', 'packaging');
            // Normalize optional fields and numbers before checking the retry token.
            $payload = [
                'product_id' => (int) $data['product_id'], 'harvest_id' => (int) $data['harvest_id'], 'packaged_on' => $data['packaged_on'],
                'packet_count' => (int) $data['packet_count'], 'wastage_grams' => FreshQuantity::grams((string) $data['wastage']),
                'polythene_id' => (int) $data['polythene_id'], 'label_id' => empty($data['label_id']) ? null : (int) $data['label_id'],
                'packaging_cost' => (string) BigDecimal::of($data['packaging_cost'])->toScale(2),
                'fresh_cost_per_kg' => isset($data['fresh_cost_per_kg']) && $data['fresh_cost_per_kg'] !== '' ? (string) BigDecimal::of($data['fresh_cost_per_kg'])->toScale(2) : null,
                'expires_on' => $data['expires_on'] ?? null, 'notes' => $data['notes'] ?? null,
            ];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if ($existing = PackagingRun::where('request_id', $data['request_id'])->first()) {
                if ($existing->user_id !== $actor->id || $existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['request_id' => 'This reference has already been used for another packaging run.']);
                }

                return $existing;
            }
            $product = Product::lockForUpdate()->findOrFail($payload['product_id']);
            $harvest = Harvest::lockForUpdate()->findOrFail($payload['harvest_id']);
            if (! $product->is_active || $product->type !== 'packaged' || $product->variety !== $harvest->variety || $harvest->harvested_on > $payload['packaged_on']) {
                throw ValidationException::withMessages(['harvest_id' => 'Choose an active product and a matching variety harvested by the packaging date.']);
            }
            $packed = $product->pack_size_grams * $payload['packet_count'];
            $freshGrams = $packed + $payload['wastage_grams'];
            if ($freshGrams > 1000000000) {
                throw ValidationException::withMessages(['packet_count' => 'A packaging run cannot use more than 1,000,000 kg.']);
            }
            $supplies = Material::with('unit')->whereIn('id', array_filter([$payload['polythene_id'], $payload['label_id']]))->orderBy('id')->lockForUpdate()->get();
            if ($supplies->count() !== ($payload['label_id'] ? 2 : 1)) {
                throw ValidationException::withMessages(['polythene_id' => 'Select different active retail materials.']);
            }
            $run = PackagingRun::create([
                'user_id' => $actor->id, 'product_id' => $product->id, 'harvest_id' => $harvest->id,
                'request_id' => $data['request_id'], 'payload_hash' => $hash, 'packaged_on' => $payload['packaged_on'], 'expires_on' => $payload['expires_on'],
                'product_name' => $product->name, 'product_sku' => $product->sku, 'variety' => $product->variety, 'pack_size_grams' => $product->pack_size_grams,
                'packet_count' => $payload['packet_count'], 'packed_grams' => $packed, 'wastage_grams' => $payload['wastage_grams'], 'fresh_grams' => $freshGrams,
                'currency' => $farm->currency, 'fresh_cost_per_kg' => $payload['fresh_cost_per_kg'], 'packaging_cost' => $payload['packaging_cost'], 'notes' => $payload['notes'],
            ]);
            // Replay the complete timeline so backdated runs cannot consume stock reserved by later sales or runs.
            $this->fresh->assertTimeline();
            $materialsCost = BigDecimal::of(0);
            foreach ($supplies as $material) {
                if (! $material->is_active || $material->supply_type !== 'retail' || ! $material->unit->is_active || $material->unit->dimension !== 'count') {
                    throw ValidationException::withMessages(['polythene_id' => 'Packaging and labels must be active retail materials measured in count units.']);
                }
                // Count-unit factor is the number of pieces per stock unit (for example a roll of 100 labels).
                $quantity = MaterialQuantity::decimal(MaterialQuantity::convert((string) $payload['packet_count'], new MaterialUnit(['dimension' => 'count', 'factor' => '1']), $material->unit));
                $movement = $this->materials->consumePackaging($actor, $material, $run, $quantity);
                $cost = BigDecimal::of($movement->value_change)->negated();
                $materialsCost = $materialsCost->plus($cost);
                $run->materials()->create([
                    'material_id' => $material->id, 'purpose' => $material->id === $payload['polythene_id'] ? 'polythene' : 'labels',
                    'material_name' => $material->name, 'unit_symbol' => $material->unit->symbol, 'quantity' => (string) $quantity, 'total_cost' => (string) $cost,
                ]);
            }
            $freshRate = $harvest->cost_per_kg ?? $payload['fresh_cost_per_kg'];
            if ($harvest->cost_per_kg !== null && Batch::whereKey($harvest->batch_id)->value('currency') !== $farm->currency) {
                throw ValidationException::withMessages(['harvest_id' => 'Allocated harvest costs use another currency.']);
            }
            $freshCost = $freshRate !== null ? BigDecimal::of($freshRate)->multipliedBy(FreshQuantity::kg($freshGrams))->toScale(6, RoundingMode::HalfUp) : null;
            $total = $freshCost?->plus($materialsCost)->plus($payload['packaging_cost'])->toScale(6);
            // Complete immutable snapshot before returning the run; there is no editable posting endpoint.
            $costs = ['materials_cost' => (string) $materialsCost->toScale(6), 'fresh_cost' => $freshCost === null ? null : (string) $freshCost,
                'total_cost' => $total === null ? null : (string) $total,
                'cost_per_packet' => $total === null ? null : (string) $total->dividedBy($payload['packet_count'], 6, RoundingMode::HalfUp)];
            DB::table('packaging_runs')->where('id', $run->id)->update($costs);
            FinishedStockMovement::create(['product_id' => $product->id, 'packaging_run_id' => $run->id, 'moved_on' => $run->packaged_on, 'quantity' => $payload['packet_count'], 'unit_cost' => $costs['cost_per_packet']]);

            return $run->refresh();
        });
    }
}
