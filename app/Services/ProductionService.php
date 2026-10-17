<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function preview(User $actor, array $data): string
    {
        return DB::transaction(function () use ($actor, $data) {
            Gate::forUser($actor->fresh())->authorize('access-section', 'production');
            $farm = Farm::firstOrFail();
            $data['reference'] = $farm->tracksBatches() ? ($data['reference'] ?? null) : null;
            $data['location'] = $farm->mode === 'advanced' ? ($data['location'] ?? '') : '';
            $plan = $this->plan($data);

            return Crypt::encryptString(json_encode(['actor' => $actor->id, 'expires' => now()->addMinutes(20)->timestamp, 'currency' => $farm->currency, 'data' => $data, 'plan' => $plan], JSON_THROW_ON_ERROR));
        });
    }

    public function decode(string $quote): array
    {
        try {
            return json_decode(Crypt::decryptString($quote), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $error) {
            throw ValidationException::withMessages(['quote' => 'The production preview is invalid. Create a new preview.']);
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function plan(array $data): array
    {
        $recipe = Recipe::lockForUpdate()->findOrFail($data['recipe_id']);
        $ingredients = $recipe->ingredients()->with('unit')->orderBy('material_id')->get();
        if ($ingredients->isEmpty()) {
            throw ValidationException::withMessages(['recipe_id' => 'The recipe has no ingredients.']);
        }
        $materials = Material::with('unit')->whereIn('id', $ingredients->pluck('material_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        $materialCost = BigDecimal::zero();
        foreach ($ingredients as $ingredient) {
            $material = $materials->get($ingredient->material_id);
            if (! $material?->is_active || $material->supply_type !== 'growing' || ! $material->unit->is_active || ! $ingredient->unit->is_active) {
                throw ValidationException::withMessages(['recipe_id' => 'A recipe ingredient or unit is inactive or archived. Update the recipe first.']);
            }
            $referenceMicros = MaterialQuantity::convert($ingredient->quantity, $ingredient->unit, $material->unit);
            $required = BigDecimal::of($referenceMicros)->multipliedBy($data['bags'])->dividedBy($recipe->reference_bags, 0, RoundingMode::Ceiling);
            if ($required->isGreaterThan(MaterialQuantity::MAX)) {
                throw ValidationException::withMessages(['bags' => 'Scaled ingredient quantities exceed the stock limit.']);
            }
            $micros = $required->toInt();
            $scaledInput = BigDecimal::of($ingredient->quantity)->multipliedBy($data['bags'])->dividedBy($recipe->reference_bags, 6, RoundingMode::Ceiling);
            if ($scaledInput->isGreaterThan('999999999999.999999')) {
                throw ValidationException::withMessages(['bags' => 'Scaled recipe quantities exceed the supported range.']);
            }
            $balance = (int) $material->movements()->sum('quantity_micros');
            if ($balance < $micros) {
                throw ValidationException::withMessages(['recipe_id' => 'Insufficient stock for '.$material->name.'. Required '.MaterialQuantity::decimal($micros).' '.$material->unit->symbol.', available '.MaterialQuantity::decimal($balance).'.']);
            }
            $value = BigDecimal::of($material->inventory_value)->multipliedBy($micros)->dividedBy($balance, 6, RoundingMode::HalfUp);
            $lines[] = [
                'material_id' => $material->id, 'material_name' => $material->name, 'material_unit_id' => $ingredient->material_unit_id,
                'stock_unit_id' => $material->material_unit_id, 'input_unit' => $ingredient->unit->symbol, 'stock_unit' => $material->unit->symbol,
                'recipe_quantity' => $ingredient->quantity, 'reference_bags' => $recipe->reference_bags,
                'quantity' => (string) $scaledInput,
                'stock_quantity' => MaterialQuantity::decimal($micros), 'unit_cost' => (string) $value->dividedBy(MaterialQuantity::decimal($micros), 6, RoundingMode::HalfUp),
                'total_cost' => (string) $value, 'available' => MaterialQuantity::decimal($balance),
            ];
            $materialCost = $materialCost->plus($value);
        }
        $total = $materialCost->plus((string) $data['labour_cost'])->plus((string) $data['additional_cost'])->toScale(6);
        if ($total->isGreaterThan('999999999999999999.999999')) {
            throw ValidationException::withMessages(['bags' => 'Production cost exceeds the supported range.']);
        }

        return [
            'recipe_name' => $recipe->name, 'variety' => $recipe->variety, 'revision' => $recipe->revision, 'ingredients' => $lines,
            'material_cost' => (string) $materialCost->toScale(6), 'total_cost' => (string) $total,
            'cost_per_bag' => (string) $total->dividedBy($data['bags'], 6, RoundingMode::HalfUp),
        ];
    }

    public function confirm(User $actor, string $quote): Batch
    {
        $preview = $this->decode($quote);
        abort_unless($preview['actor'] === $actor->id, 403);

        return DB::transaction(function () use ($actor, $preview) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('access-section', 'production');
            $data = $preview['data'];
            $hash = $this->hash($data);
            if ($existing = Batch::where('request_id', $data['request_id'])->first()) {
                if ($existing->payload_hash !== $hash || $existing->user_id !== $actor->id) {
                    throw ValidationException::withMessages(['quote' => 'This production submission reference is already used.']);
                }

                return $existing;
            }
            if ($preview['expires'] < now()->timestamp || $preview['currency'] !== $farm->currency) {
                throw ValidationException::withMessages(['quote' => 'The preview expired or farm currency changed. Refresh the material costs.']);
            }
            $plan = $this->plan($data);
            if ($this->hash($plan) !== $this->hash($preview['plan'])) {
                throw ValidationException::withMessages(['quote' => 'Recipe or stock costs changed. Refresh the preview before confirming.']);
            }
            $batch = new Batch;
            $batch->forceFill([
                'code' => 'MF-'.Str::upper((string) Str::ulid()), 'recipe_id' => $data['recipe_id'], 'recipe_name' => $plan['recipe_name'], 'variety' => $plan['variety'],
                'user_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'currency' => $preview['currency'],
                // The encrypted preview already normalized optional details for its creation mode.
                // A later mode change must not discard details the farmer approved.
                'reference' => $data['reference'] ?? null, 'location' => $data['location'] ?? '',
                'started_on' => $data['started_on'], 'bags' => $data['bags'], 'notes' => $data['notes'] ?? null, 'expected_harvest_on' => $data['expected_harvest_on'] ?? null,
                'stage' => 'prepared', 'prepared_on' => $data['started_on'], 'production_status' => 'confirmed', 'labour_cost' => $data['labour_cost'], 'additional_cost' => $data['additional_cost'],
                'material_cost' => $plan['material_cost'], 'total_cost' => $plan['total_cost'], 'cost_per_bag' => $plan['cost_per_bag'],
            ])->save();
            foreach ($plan['ingredients'] as $line) {
                $material = Material::findOrFail($line['material_id']);
                $movement = app(MaterialStockService::class)->consumeProduction($actor, $material, $batch, $line['stock_quantity']);
                if (! BigDecimal::of($movement->value_change)->negated()->isEqualTo($line['total_cost'])) {
                    throw ValidationException::withMessages(['quote' => 'Ingredient valuation changed. Refresh the production preview.']);
                }
                unset($line['available']);
                $batch->ingredients()->create($line);
            }
            $this->audit($actor, $batch, 'confirmed', 'Recipe production confirmed and materials deducted', [], $batch->only(['bags', 'recipe_name', 'production_status', 'total_cost']));

            return $batch;
        });
    }

    public function update(User $actor, Batch $batch, array $data, bool $correction = false): void
    {
        DB::transaction(function () use ($actor, $batch, $data, $correction) {
            Farm::lockForUpdate()->firstOrFail();
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);
            $gate = Gate::forUser($actor->fresh());
            $correction ? $gate->authorize('correct', $batch) : $gate->authorize('access-section', 'production');
            if ($batch->production_status === 'voided') {
                throw ValidationException::withMessages(['reason' => 'Voided production cannot be changed.']);
            }
            $reason = $data['reason'];
            unset($data['reason']);
            if (array_key_exists('location', $data)) {
                $data['location'] ??= '';
            }
            $sold = (int) ($data['sold_bags'] ?? $batch->sold_bags);
            $invoiced = (int) DB::table('invoice_allocations')->where('batch_id', $batch->id)->sum('quantity');
            $restocked = (int) DB::table('invoice_return_allocations as ra')->join('invoice_allocations as a', 'a.id', '=', 'ra.invoice_allocation_id')->join('invoice_return_lines as rl', 'rl.id', '=', 'ra.invoice_return_line_id')->where('a.batch_id', $batch->id)->where('rl.restock', true)->sum('ra.quantity');
            if ($sold < $invoiced - $restocked) {
                throw ValidationException::withMessages(['sold_bags' => 'Sold bags cannot be reduced below the quantity still allocated to invoices. Record a sales return first.']);
            }
            $damaged = (int) ($data['damaged_bags'] ?? $batch->damaged_bags);
            $contaminated = (int) ($data['contaminated_bags'] ?? $batch->contaminated_bags);
            $discarded = (int) ($data['discarded_bags'] ?? $batch->discarded_bags);
            if ($sold + $damaged + $contaminated + $discarded > $batch->bags) {
                throw ValidationException::withMessages(['reason' => 'Sold, damaged, contaminated and discarded bags cannot exceed produced bags.']);
            }
            if (isset($data['stage'])) {
                if (array_search($data['stage'], Batch::STAGES) < array_search($batch->stage, Batch::STAGES) && ! $gate->allows('correct', $batch)) {
                    throw ValidationException::withMessages(['stage' => 'Only an Owner or Manager can correct a stage backwards.']);
                }
                $stageDate = $data['stage'].'_on';
                if ($data['stage'] !== $batch->stage && empty($data[$stageDate]) && ! $batch->{$stageDate}) {
                    $data[$stageDate] = now()->toDateString();
                }
                $previous = $batch->started_on;
                foreach (Batch::STAGES as $stage) {
                    $field = $stage.'_on';
                    $value = array_key_exists($field, $data) ? $data[$field] : $batch->{$field};
                    if ($value && $value < $previous) {
                        throw ValidationException::withMessages([$field => 'Growth dates must follow production and stage order.']);
                    }
                    if ($value) {
                        $previous = $value;
                    }
                }
                $firstHarvest = $batch->harvests()->min('harvested_on');
                $lastHarvest = $batch->harvests()->max('harvested_on');
                foreach (['prepared_on', 'incubating_on', 'fruiting_on', 'harvesting_on'] as $field) {
                    $value = array_key_exists($field, $data) ? $data[$field] : $batch->{$field};
                    if ($firstHarvest && $value && $value > $firstHarvest) {
                        throw ValidationException::withMessages([$field => 'A growth stage cannot begin after an already recorded harvest.']);
                    }
                }
                $completed = $data['completed_on'] ?? $batch->completed_on;
                if ($lastHarvest && $completed && $completed < $lastHarvest) {
                    throw ValidationException::withMessages(['completed_on' => 'Completion cannot precede the last recorded harvest.']);
                }
            }
            $before = $batch->only(array_keys($data));
            $batch->forceFill($data)->save();
            $this->audit($actor, $batch, $correction ? 'corrected' : 'stage_updated', $reason, $before, $batch->only(array_keys($data)));
        });
    }

    public function void(User $actor, Batch $batch, string $reason): void
    {
        DB::transaction(function () use ($actor, $batch, $reason) {
            Farm::lockForUpdate()->firstOrFail();
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);
            Gate::forUser($actor->fresh())->authorize('void', $batch);
            if ($batch->production_status === 'voided') {
                return;
            }
            if ($batch->production_status !== 'confirmed' || $batch->harvests()->exists() || $batch->sold_bags || $batch->damaged_bags || $batch->contaminated_bags || $batch->discarded_bags) {
                throw ValidationException::withMessages(['reason' => 'Only confirmed, unused production without harvests, sales, damage or contamination can be voided.']);
            }
            $before = $batch->only(['production_status', 'stage']);
            $ingredients = $batch->ingredients()->orderBy('material_id')->get();
            $materials = Material::withTrashed()->whereIn('id', $ingredients->pluck('material_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ingredients as $line) {
                app(MaterialStockService::class)->restoreProduction($actor, $materials[$line->material_id], $batch, $line->stock_quantity, $line->total_cost);
            }
            $batch->forceFill(['production_status' => 'voided', 'stage' => 'completed'])->save();
            $this->audit($actor, $batch, 'voided', $reason, $before, $batch->only(['production_status', 'stage']));
        });
    }

    private function audit(User $actor, Batch $batch, string $action, string $reason, array $before, array $after): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'An audit reason is required.']);
        }
        $batch->audits()->create(['user_id' => $actor->id, 'action' => $action, 'reason' => $reason, 'before' => $before, 'after' => $after]);
    }
}
