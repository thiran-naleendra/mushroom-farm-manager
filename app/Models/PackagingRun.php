<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagingRun extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(['fresh_cost', 'materials_cost', 'packaging_cost', 'total_cost', 'cost_per_packet'], 'decimal:6') + ['fresh_cost_per_kg' => 'decimal:2'];
    }

    public function materials(): HasMany
    {
        return $this->hasMany(PackagingMaterial::class);
    }
}
