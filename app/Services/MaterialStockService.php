<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\MaterialUnit;
use App\Models\PackagingRun;
use App\Models\PurchaseLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class MaterialStockService
{
    public function adjust(User $actor, Material $material, array $data): MaterialMovement
    {
        return $this->record($actor, $material, $data);
    }

    public function receivePurchase(User $actor, Material $material, PurchaseLine $line): MaterialMovement
    {
        return $this->record($actor, $material, [
            'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'mushroom-farm-manager/purchase-line/'.$line->id), 'type' => 'receipt',
            'material_unit_id' => $line->stock_unit_id, 'quantity' => $line->stock_quantity,
            'reason' => 'Purchase #'.$line->purchase_id.' received',
        ], $line, $line->total);
    }

    public function returnPurchase(User $actor, Material $material, PurchaseLine $line, string $quantity, string $requestId, string $reason): MaterialMovement
    {
        return $this->record($actor, $material, [
            'request_id' => $requestId, 'type' => 'purchase_return',
            'material_unit_id' => $line->stock_unit_id, 'quantity' => $quantity, 'reason' => $reason,
        ], $line);
    }

    public function consumeProduction(User $actor, Material $material, Batch $batch, string $quantity): MaterialMovement
    {
        return $this->record($actor, $material, [
            'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'production/'.$batch->id.'/material/'.$material->id),
            'type' => 'production', 'material_unit_id' => $material->material_unit_id, 'quantity' => $quantity,
            'reason' => 'Materials consumed for production',
        ], batch: $batch);
    }

    public function restoreProduction(User $actor, Material $material, Batch $batch, string $quantity, string $value): MaterialMovement
    {
        return $this->record($actor, $material, [
            'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'production-void/'.$batch->id.'/material/'.$material->id),
            'type' => 'production_reverse', 'material_unit_id' => $material->material_unit_id, 'quantity' => $quantity,
            'reason' => 'Audited production void: original material quantities and costs restored',
        ], receiptValue: $value, batch: $batch);
    }

    public function consumePackaging(User $actor, Material $material, PackagingRun $run, string $quantity): MaterialMovement
    {
        return $this->record($actor, $material, [
            'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'packaging/'.$run->id.'/material/'.$material->id),
            'type' => 'packaging', 'material_unit_id' => $material->material_unit_id, 'quantity' => $quantity,
            'reason' => 'Retail packaging #'.$run->id,
        ], packaging: $run);
    }

    private function record(User $actor, Material $material, array $data, ?PurchaseLine $line = null, ?string $receiptValue = null, ?Batch $batch = null, ?PackagingRun $packaging = null): MaterialMovement
    {
        return DB::transaction(function () use ($actor, $material, $data, $line, $receiptValue, $batch, $packaging) {
            $reversal = $data['type'] === 'production_reverse';
            $material = ($reversal ? Material::withTrashed() : Material::query())->lockForUpdate()->findOrFail($material->id);
            $gate = Gate::forUser($actor->fresh());
            if ($packaging) {
                $gate->authorize('access-section', 'packaging');
            } elseif ($batch) {
                $reversal ? $gate->authorize('void', $batch) : $gate->authorize('access-section', 'production');
            } else {
                $gate->authorize('update', $material);
            }
            if ($line && ($line->material_id !== $material->id || $line->stock_unit_id !== $material->material_unit_id)) {
                throw ValidationException::withMessages(['quantity' => 'The purchase line does not match this material and stock unit.']);
            }
            $existing = MaterialMovement::where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ($existing->material_id != $material->id || $existing->user_id != $actor->id || $existing->material_unit_id != $data['material_unit_id'] || $existing->type !== $data['type'] || $existing->reason !== $data['reason'] || MaterialQuantity::micros($existing->input_quantity) !== MaterialQuantity::micros((string) $data['quantity'])) {
                    throw ValidationException::withMessages(['quantity' => 'This adjustment reference has already been used. Reload the form.']);
                }

                return $existing;
            }
            if (! $material->is_active && ! $reversal) {
                throw ValidationException::withMessages(['quantity' => 'Activate this material before adjusting its stock.']);
            }
            $inputUnit = MaterialUnit::when(! $reversal, fn ($q) => $q->where('is_active', true))->lockForUpdate()->find($data['material_unit_id']);
            if (! $inputUnit) {
                throw ValidationException::withMessages(['material_unit_id' => 'Choose an active unit.']);
            }
            $quantity = MaterialQuantity::convert((string) $data['quantity'], $inputUnit, $material->unit);
            if ($quantity <= 0 || ! in_array($data['type'], ['opening', 'add', 'remove', 'receipt', 'purchase_return', 'production', 'production_reverse', 'packaging']) || trim($data['reason']) === '') {
                throw ValidationException::withMessages(['quantity' => 'Enter a positive adjustment quantity and a reason.']);
            }
            // The ledger is authoritative; the locked material row serializes all writers.
            $balance = (int) $material->movements()->sum('quantity_micros');
            $removal = in_array($data['type'], ['remove', 'purchase_return', 'production', 'packaging']);
            $delta = $removal ? -$quantity : $quantity;
            $next = $balance + $delta;
            if ($next < 0 || $next > MaterialQuantity::MAX) {
                throw ValidationException::withMessages(['quantity' => 'This adjustment would create negative stock or exceed the stock limit.']);
            }
            $oldValue = BigDecimal::of($material->inventory_value ?? '0');
            $value = $receiptValue !== null ? BigDecimal::of($receiptValue) : ($removal
                ? ($next === 0 ? $oldValue : $oldValue->multipliedBy($quantity)->dividedBy($balance, 6, RoundingMode::HalfUp))
                : BigDecimal::of(MaterialQuantity::decimal($quantity))->multipliedBy($material->unit_cost));
            $valueChange = $removal ? $value->negated() : $value;
            $nextValue = $oldValue->plus($valueChange)->toScale(6, RoundingMode::HalfUp);
            $average = $next > 0 ? $nextValue->dividedBy(MaterialQuantity::decimal($next), 6, RoundingMode::HalfUp) : BigDecimal::of('0.000000');
            if ($nextValue->isNegative() || $average->isGreaterThan('999999999.99')) {
                throw ValidationException::withMessages(['quantity' => 'The resulting inventory value or cost is outside the supported range.']);
            }
            $movement = $material->movements()->create([
                'user_id' => $actor->id, 'request_id' => $data['request_id'], 'type' => $data['type'],
                'material_unit_id' => $inputUnit->id, 'input_quantity' => $data['quantity'],
                'quantity' => MaterialQuantity::decimal($delta), 'quantity_micros' => $delta, 'balance_after' => MaterialQuantity::decimal($next),
                'unit_cost' => (string) $value->dividedBy(MaterialQuantity::decimal($quantity), 2, RoundingMode::HalfUp), 'reason' => $data['reason'],
                'purchase_line_id' => $line?->id, 'value_change' => (string) $valueChange->toScale(6, RoundingMode::HalfUp), 'value_after' => (string) $nextValue,
                'batch_id' => $batch?->id,
                'packaging_run_id' => $packaging?->id,
            ]);
            $material->quantity = MaterialQuantity::decimal($next);
            $material->inventory_value = (string) $nextValue;
            $material->average_cost = (string) $average;
            if ($receiptValue !== null) {
                $material->unit_cost = (string) $average->toScale(2, RoundingMode::HalfUp);
            }
            if ($reversal && $material->trashed()) {
                $material->restore();
                $material->is_active = false;
            }
            $material->save();

            return $movement;
        });
    }
}
