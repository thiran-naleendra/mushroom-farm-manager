<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class HarvestService
{
    public function record(User $actor, array $data): Harvest
    {
        return DB::transaction(function () use ($actor, $data) {
            Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('access-section', 'harvests');
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($existing = Harvest::where('request_id', $data['request_id'])->first()) {
                if ($existing->payload_hash !== $hash || $existing->user_id !== $actor->id) {
                    throw ValidationException::withMessages(['weight' => 'This harvest submission reference is already used. Reload the form.']);
                }

                return $existing;
            }
            $batch = isset($data['batch_id']) ? Batch::lockForUpdate()->findOrFail($data['batch_id']) : null;
            if ($batch) {
                if ($batch->stage === 'completed' || $batch->production_status === 'voided' || $batch->availableBags() <= 0 || $data['harvested_on'] < $batch->started_on) {
                    throw ValidationException::withMessages(['batch_id' => 'Choose active production with healthy bags and a date on or after its start.']);
                }
                foreach (['prepared_on', 'incubating_on', 'fruiting_on'] as $date) {
                    if ($batch->{$date} && $data['harvested_on'] < $batch->{$date}) {
                        throw ValidationException::withMessages(['harvested_on' => 'The harvest cannot precede recorded growth dates.']);
                    }
                }
                if (! empty($data['variety']) && mb_strtolower(trim($data['variety'])) !== mb_strtolower(trim($batch->variety))) {
                    throw ValidationException::withMessages(['variety' => 'The mushroom variety must match the linked production.']);
                }
            }
            $gross = FreshQuantity::grams((string) $data['weight']);
            $rejected = FreshQuantity::grams((string) ($data['rejected_weight'] ?? '0'));
            if ($rejected > $gross) {
                throw ValidationException::withMessages(['rejected_weight' => 'Rejected quantity cannot exceed total harvested quantity.']);
            }
            $cycle = $batch ? ($data['cycle'] ?? ((int) $batch->harvests()->max('cycle') + 1)) : null;
            $harvest = Harvest::create([
                'batch_id' => $batch?->id, 'user_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash,
                'variety' => $batch?->variety ?? $data['variety'], 'harvested_on' => $data['harvested_on'], 'cycle' => $cycle,
                'gross_weight' => FreshQuantity::kg($gross), 'rejected_weight' => FreshQuantity::kg($rejected), 'weight' => FreshQuantity::kg($gross - $rejected), 'notes' => $data['notes'] ?? null,
            ]);
            if ($batch) {
                $before = $batch->only(['stage', 'harvesting_on']);
                $batch->forceFill(['stage' => 'harvesting', 'harvesting_on' => $batch->harvesting_on ? min($batch->harvesting_on, $data['harvested_on']) : $data['harvested_on']])->save();
                $batch->audits()->create(['user_id' => $actor->id, 'action' => 'harvest_recorded', 'reason' => 'Harvest recorded; only saleable weight credited to fresh stock', 'before' => $before, 'after' => $batch->only(['stage', 'harvesting_on']) + ['harvest_id' => $harvest->id, 'cycle' => $cycle, 'saleable_kg' => $harvest->weight]]);
            }

            return $harvest;
        });
    }
}
