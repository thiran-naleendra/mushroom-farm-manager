<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function save(User $user, array $data, ?Expense $expense = null): Expense
    {
        return DB::transaction(function () use ($user, $data, $expense) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($user->fresh())->authorize('create', Expense::class);
            $requestId = $data['request_id'] ?? null;
            unset($data['request_id']);
            $hash = null;
            if (! $expense && $requestId) {
                $payload = [
                    'description' => $data['description'], 'spent_on' => $data['spent_on'], 'amount' => (string) PurchaseAmounts::money($data['amount']),
                    'expense_category_id' => empty($data['expense_category_id']) ? null : (int) $data['expense_category_id'],
                    'batch_id' => empty($data['batch_id']) ? null : (int) $data['batch_id'], 'treatment' => $data['treatment'] ?? 'operating', 'notes' => $data['notes'] ?? null,
                ];
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                if ($existing = Expense::withTrashed()->where('request_id', $requestId)->first()) {
                    if ($existing->user_id !== $user->id || $existing->payload_hash !== $hash) {
                        throw ValidationException::withMessages(['request_id' => 'This submission reference has already been used. Reload the expense form.']);
                    }

                    return $existing;
                }
            }
            $expense = $expense ? Expense::lockForUpdate()->findOrFail($expense->id) : new Expense;
            $before = $expense->exists ? $expense->toArray() : null;
            if ($expense->exists && (int) ($data['revision'] ?? 0) !== $expense->revision) {
                throw ValidationException::withMessages(['revision' => 'This expense changed. Reload before saving.']);
            }
            $data['treatment'] ??= 'operating';
            if ($data['treatment'] === 'production_payment') {
                $batch = Batch::lockForUpdate()->find($data['batch_id'] ?? null);
                if (! $batch || $batch->production_status !== 'confirmed' || $batch->currency !== $farm->currency || $data['spent_on'] < $batch->started_on) {
                    throw ValidationException::withMessages(['batch_id' => 'Choose confirmed production in the same currency, on or before this payment.']);
                }
                $used = BigDecimal::zero();
                foreach (Expense::where('batch_id', $batch->id)->where('treatment', 'production_payment')->when($expense->exists, fn ($q) => $q->whereKeyNot($expense->id))->pluck('amount') as $amount) {
                    $used = $used->plus(PurchaseAmounts::money($amount));
                }
                $budget = BigDecimal::of($batch->labour_cost)->plus($batch->additional_cost);
                if ($used->plus($data['amount'])->isGreaterThan($budget)) {
                    throw ValidationException::withMessages(['amount' => 'Payments exceed the labour and additional costs already included in this production.']);
                }
            }
            $expense->fill($data);
            $expense->currency ??= $farm->currency;
            if (! $expense->exists) {
                $expense->user_id = $user->id;
                $expense->request_id = $requestId;
                $expense->payload_hash = $hash;
            }
            $expense->revision = ($before['revision'] ?? 0) + 1;
            $expense->save();
            $this->audit($expense, $user, $before ? 'updated' : 'created', $before);

            return $expense;
        });
    }

    public function remove(User $user, Expense $expense): void
    {
        DB::transaction(function () use ($user, $expense) {
            Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($user->fresh())->authorize('delete', $expense);
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            $before = $expense->toArray();
            $expense->delete();
            $this->audit($expense, $user, 'deleted', $before);
        });
    }

    private function audit(Expense $expense, User $user, string $action, ?array $before): void
    {
        DB::table('expense_audits')->insert(['expense_id' => $expense->id, 'user_id' => $user->id, 'action' => $action, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($expense->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
