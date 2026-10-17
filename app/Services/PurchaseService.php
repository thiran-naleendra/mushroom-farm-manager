<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function create(User $actor, array $data): Purchase
    {
        return DB::transaction(function () use ($actor, $data) {
            Gate::forUser($actor->fresh())->authorize('create', Purchase::class);
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($existing = Purchase::where('request_id', $data['request_id'])->first()) {
                if ($existing->payload_hash !== $hash || $existing->user_id !== $actor->id) {
                    throw ValidationException::withMessages(['reference' => 'This submission reference is already used. Reload the purchase form.']);
                }

                return $existing;
            }
            if (Purchase::where('supplier_id', $supplier->id)->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'This supplier reference already exists.']);
            }
            $materials = Material::whereIn('id', array_column($data['lines'], 'material_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units = MaterialUnit::whereIn('id', array_column($data['lines'], 'material_unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subtotal = BigDecimal::zero();
            $discount = BigDecimal::zero();
            $lines = [];
            foreach ($data['lines'] as $index => $input) {
                $material = $materials->get($input['material_id']);
                $unit = $units->get($input['material_unit_id']);
                if (! $material?->is_active || ! $unit?->is_active) {
                    throw ValidationException::withMessages(['lines.'.$index.'.material_id' => 'Choose an active material and unit.']);
                }
                $converted = MaterialQuantity::convert((string) $input['quantity'], $unit, $material->unit);
                $gross = PurchaseAmounts::money(BigDecimal::of((string) $input['quantity'])->multipliedBy((string) $input['unit_price']));
                $lineDiscount = PurchaseAmounts::money($input['discount']);
                if ($lineDiscount->isGreaterThan($gross) || $gross->isGreaterThan('999999999999.99')) {
                    throw ValidationException::withMessages(['lines.'.$index.'.discount' => 'Discount cannot exceed the line subtotal, and line subtotal must be at most 999999999999.99.']);
                }
                $lines[] = [
                    'material_id' => $material->id, 'material_unit_id' => $unit->id, 'stock_unit_id' => $material->material_unit_id,
                    'material_name' => $material->name, 'quantity' => $input['quantity'], 'stock_quantity' => MaterialQuantity::decimal($converted),
                    'unit_price' => $input['unit_price'], 'discount' => (string) $lineDiscount, 'total' => (string) $gross->minus($lineDiscount),
                ];
                $subtotal = $subtotal->plus($gross);
                $discount = $discount->plus($lineDiscount);
            }
            $purchase = Purchase::create([
                'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'user_id' => $actor->id,
                'purchased_on' => $data['purchased_on'], 'reference' => $data['reference'], 'request_id' => $data['request_id'], 'payload_hash' => $hash,
                'status' => 'pending', 'subtotal' => (string) $subtotal, 'discount' => (string) $discount, 'total' => (string) $subtotal->minus($discount),
            ]);
            $purchase->lines()->createMany($lines);

            return $purchase;
        });
    }

    public function receive(User $actor, Purchase $purchase): void
    {
        DB::transaction(function () use ($actor, $purchase) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            Gate::forUser($actor->fresh())->authorize('receive', $purchase);
            if ($purchase->status === 'received') {
                return;
            }
            $lines = $purchase->lines()->orderBy('material_id')->get();
            $materials = Material::whereIn('id', $lines->pluck('material_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $material = $materials->get($line->material_id);
                if (! $material || $material->material_unit_id !== $line->stock_unit_id) {
                    throw ValidationException::withMessages(['purchase' => 'A material is archived or its stock unit changed. Receiving was not applied.']);
                }
                app(MaterialStockService::class)->receivePurchase($actor, $material, $line);
            }
            $purchase->update(['status' => 'received', 'received_at' => now()]);
        });
    }

    public function payment(User $actor, Purchase $purchase, array $data): void
    {
        DB::transaction(function () use ($actor, $purchase, $data) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            Gate::forUser($actor->fresh())->authorize('pay', $purchase);
            if ($existing = PurchasePayment::where('request_id', $data['request_id'])->first()) {
                if ($existing->purchase_id !== $purchase->id || $existing->type !== $data['type'] || $existing->user_id !== $actor->id || $existing->paid_on !== $data['paid_on'] || $existing->reference !== $data['reference'] || ! PurchaseAmounts::money($existing->amount)->isEqualTo(PurchaseAmounts::money($data['amount']))) {
                    throw ValidationException::withMessages(['amount' => 'This payment reference has already been submitted with different details.']);
                }

                return;
            }
            $summary = PurchaseAmounts::summary($purchase);
            $balance = PurchaseAmounts::money($summary['balance']);
            $amount = PurchaseAmounts::money($data['amount']);
            $available = $data['type'] === 'payment' ? $balance : $balance->negated();
            if ($available->isLessThan($amount) || $amount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds the outstanding balance or refundable supplier credit.']);
            }
            $purchase->payments()->create($data + ['user_id' => $actor->id]);
        });
    }

    public function returnItems(User $actor, Purchase $purchase, array $data): void
    {
        DB::transaction(function () use ($actor, $purchase, $data) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            Gate::forUser($actor->fresh())->authorize('receive', $purchase);
            $line = $purchase->lines()->findOrFail($data['purchase_line_id']);
            if ($existing = PurchaseReturn::where('request_id', $data['request_id'])->first()) {
                if ($existing->purchase_line_id !== $line->id || $existing->user_id !== $actor->id || $existing->returned_on !== $data['returned_on'] || $existing->reason !== $data['reason'] || MaterialQuantity::micros($existing->quantity) !== MaterialQuantity::micros((string) $data['quantity'])) {
                    throw ValidationException::withMessages(['quantity' => 'This return reference has already been used.']);
                }

                return;
            }
            if ($purchase->status !== 'received' || $data['returned_on'] < $purchase->received_at->toDateString()) {
                throw ValidationException::withMessages(['returned_on' => 'Returns must be dated on or after receiving.']);
            }
            $returned = $line->returns()->get();
            $priorQuantity = $returned->reduce(fn (int $sum, $row) => $sum + MaterialQuantity::micros($row->quantity), 0);
            $quantity = MaterialQuantity::micros((string) $data['quantity']);
            $originalQuantity = MaterialQuantity::micros($line->quantity);
            if ($quantity <= 0 || $priorQuantity + $quantity > $originalQuantity) {
                throw ValidationException::withMessages(['quantity' => 'Return quantity exceeds the unreturned purchased quantity.']);
            }
            $material = Material::lockForUpdate()->findOrFail($line->material_id);
            $stockQuantity = MaterialQuantity::convert((string) $data['quantity'], $line->unit, $material->unit);
            $priorCredit = $returned->reduce(fn ($sum, $row) => $sum->plus($row->credit), BigDecimal::zero());
            // Cumulative rounding ensures partial returns sum exactly to the original discounted price.
            $cumulativeCredit = BigDecimal::of($line->total)->multipliedBy($priorQuantity + $quantity)->dividedBy($originalQuantity, 2, RoundingMode::HalfUp);
            $credit = $cumulativeCredit->minus($priorCredit);
            $movement = app(MaterialStockService::class)->returnPurchase($actor, $material, $line, MaterialQuantity::decimal($stockQuantity), $data['request_id'], $data['reason']);
            $carrying = BigDecimal::of($movement->value_change)->negated();
            $line->returns()->create([
                'user_id' => $actor->id, 'request_id' => $data['request_id'], 'quantity' => $data['quantity'],
                'stock_quantity' => MaterialQuantity::decimal($stockQuantity), 'credit' => (string) $credit,
                'carrying_value' => (string) $carrying, 'price_variance' => (string) $credit->minus($carrying),
                'returned_on' => $data['returned_on'], 'reason' => $data['reason'],
            ]);
        });
    }
}
