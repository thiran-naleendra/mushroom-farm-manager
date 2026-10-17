<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use App\Services\FreshQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Harvest extends Model
{
    use CastsInventoryDecimals;
    use ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:3', 'gross_weight' => 'decimal:3', 'rejected_weight' => 'decimal:3', 'allocated_cost' => 'decimal:6', 'cost_per_kg' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $harvest) {
            $harvest->saleable_grams = FreshQuantity::grams((string) $harvest->weight);
            $harvest->rejected_grams = FreshQuantity::grams((string) ($harvest->rejected_weight ?? '0'));
            $harvest->gross_grams = $harvest->saleable_grams + $harvest->rejected_grams;
            $harvest->gross_weight ??= FreshQuantity::kg($harvest->gross_grams);
            $harvest->rejected_weight ??= '0.000';
            if (! $harvest->variety && $harvest->batch_id) {
                $harvest->variety = Batch::whereKey($harvest->batch_id)->value('variety');
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
