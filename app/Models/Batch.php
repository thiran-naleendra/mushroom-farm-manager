<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    public const STAGES = ['prepared', 'incubating', 'fruiting', 'harvesting', 'completed'];

    use CastsInventoryDecimals;

    protected $fillable = ['code', 'variety', 'location', 'bags', 'started_on', 'stage', 'notes', 'contaminated_bags', 'expected_harvest_on'];

    protected static function booted(): void
    {
        static::updating(function (self $batch) {
            $frozen = ['bags', 'recipe_id', 'recipe_name', 'variety', 'code', 'reference', 'started_on', 'currency', 'material_cost', 'labour_cost', 'additional_cost', 'total_cost', 'cost_per_bag', 'request_id', 'payload_hash', 'user_id'];
            if (in_array($batch->getOriginal('production_status'), ['confirmed', 'voided']) && $batch->isDirty($frozen)) {
                throw new \LogicException('Confirmed recipe, bag quantity and ingredient costs cannot be rewritten. Void unused production and create a corrected record.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Production history cannot be deleted.'));
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(Harvest::class);
    }

    public function scopeRecordedLocations($query)
    {
        return $query->whereNotNull('location')->where('location', '!=', '')->select('location')->distinct()->orderBy('location');
    }

    protected function casts(): array
    {
        return ['material_cost' => 'decimal:6', 'labour_cost' => 'decimal:2', 'additional_cost' => 'decimal:2', 'total_cost' => 'decimal:6', 'cost_per_bag' => 'decimal:6'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductionIngredient::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(BatchAudit::class);
    }

    public function availableBags(): int
    {
        return $this->production_status === 'voided' ? 0 : $this->bags - ($this->sold_bags ?? 0) - ($this->damaged_bags ?? 0) - ($this->contaminated_bags ?? 0) - ($this->discarded_bags ?? 0);
    }
}
