<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Models\Farm;
use App\Models\Sale;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class CustomerService
{
    public function balance(Customer $customer): BigDecimal
    {
        // Sum decimal strings in PHP so SQLite and MySQL share exact money arithmetic.
        return $customer->entries()->pluck('amount')->reduce(fn ($sum, $value) => $sum->plus((string) $value), BigDecimal::of('0.00'))->toScale(2, RoundingMode::HalfUp);
    }

    public function save(User $actor, array $data, ?Customer $customer = null): Customer
    {
        return DB::transaction(function () use ($actor, $data, $customer) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            $customer = $customer ? Customer::lockForUpdate()->findOrFail($customer->id) : new Customer;
            $gate = Gate::forUser($actor->fresh());
            $customer->exists ? $gate->authorize('update', $customer) : $gate->authorize('create', Customer::class);
            $new = ! $customer->exists;
            $opening = BigDecimal::of($data['opening_balance'] ?? '0')->toScale(2);
            if (! $new && array_key_exists('opening_balance', $data)) {
                throw ValidationException::withMessages(['opening_balance' => 'Use an audited adjustment to correct an existing balance.']);
            }
            if ($new && $opening->isPositive() && ! $actor->fresh()->isOwner()) {
                throw ValidationException::withMessages(['opening_balance' => 'Only an Owner may enter an opening outstanding balance.']);
            }
            $balance = $new ? $opening : $this->balance($customer);
            if ($balance->isPositive()) {
                $this->assertWithinLimit($balance, $data['credit_limit']);
            }
            $customer->fill(collect($data)->except(['opening_balance', 'opening_reason'])->all());
            if ($new) {
                $customer->currency = $farm->currency;
            }
            $customer->save();
            if ($new && $opening->isPositive()) {
                if (trim($data['opening_reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['opening_reason' => 'Explain the opening outstanding balance.']);
                }
                $this->entry($actor, $customer, (string) $opening, 'opening', $data['opening_reason'], (string) Str::uuid());
            }

            return $customer;
        });
    }

    public function adjust(User $actor, Customer $customer, array $data): void
    {
        DB::transaction(function () use ($actor, $customer, $data) {
            Farm::lockForUpdate()->firstOrFail();
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            Gate::forUser($actor->fresh())->authorize('adjustBalance', $customer);
            $amount = BigDecimal::of($data['amount'])->toScale(2);
            $amount = $data['direction'] === 'decrease' ? $amount->negated() : $amount;
            if ($existing = CustomerAccountEntry::where('request_id', $data['request_id'])->first()) {
                if ($existing->customer_id !== $customer->id || $existing->user_id !== $actor->id || $existing->type !== 'adjustment' || $existing->amount !== (string) $amount || $existing->reason !== $data['reason']) {
                    throw ValidationException::withMessages(['request_id' => 'This reference has already been used.']);
                }

                return;
            }
            $this->assertWithinLimit($this->balance($customer)->plus($amount), $customer->credit_limit);
            $this->entry($actor, $customer, (string) $amount, 'adjustment', $data['reason'], $data['request_id']);
        });
    }

    public function recordSale(User $actor, Customer $customer, Sale $sale): void
    {
        DB::transaction(function () use ($actor, $customer, $sale) {
            Farm::lockForUpdate()->firstOrFail();
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            Gate::forUser($actor->fresh())->authorize('update', $customer);
            if ($sale->customer_id !== $customer->id || $sale->amount_paid === null) {
                throw ValidationException::withMessages(['customer_id' => 'The sale must be linked to this customer with an explicit payment amount.']);
            }
            if ($customer->entries()->where('sale_id', $sale->id)->exists()) {
                return;
            }
            if (! $customer->is_active || $customer->currency !== Farm::firstOrFail()->currency) {
                throw ValidationException::withMessages(['customer_id' => 'Choose an active customer with the current farm currency.']);
            }
            $total = BigDecimal::of((string) $sale->weight)->multipliedBy((string) $sale->price_per_kg)->toScale(2, RoundingMode::HalfUp);
            $paid = BigDecimal::of($sale->amount_paid)->toScale(2);
            if ($paid->isNegative() || $paid->isGreaterThan($total)) {
                throw ValidationException::withMessages(['amount_paid' => 'Payment must be between zero and the sale total.']);
            }
            $due = $total->minus($paid);
            $this->assertWithinLimit($this->balance($customer)->plus($due), $customer->credit_limit);
            $this->entry($actor, $customer, (string) $due, 'sale', 'Sale #'.$sale->id.' outstanding after recorded payment', (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'customer-sale/'.$sale->id), $sale->id);
        });
    }

    public function archive(User $actor, Customer $customer): void
    {
        DB::transaction(function () use ($actor, $customer) {
            Farm::lockForUpdate()->firstOrFail();
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            Gate::forUser($actor->fresh())->authorize('delete', $customer);
            if (! $this->balance($customer)->isZero()) {
                throw ValidationException::withMessages(['customer' => 'Settle the outstanding balance or deactivate this customer instead.']);
            }
            $customer->delete();
        });
    }

    private function assertWithinLimit(BigDecimal $balance, string $limit): void
    {
        if ($balance->isNegative() || $balance->isGreaterThan($limit)) {
            throw ValidationException::withMessages(['credit_limit' => 'The outstanding balance must remain between zero and the credit limit.']);
        }
    }

    private function entry(User $actor, Customer $customer, string $amount, string $type, string $reason, string $requestId, ?int $saleId = null): void
    {
        $customer->entries()->create(['user_id' => $actor->id, 'sale_id' => $saleId, 'request_id' => $requestId, 'type' => $type, 'amount' => $amount, 'currency' => $customer->currency, 'reason' => $reason]);
    }
}
