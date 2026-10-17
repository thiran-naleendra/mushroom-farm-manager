<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use CastsInventoryDecimals;
    use SoftDeletes;

    protected $fillable = ['name', 'sku', 'material_category_id', 'material_unit_id', 'supply_type', 'unit_cost', 'minimum_stock', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:2', 'minimum_stock' => 'decimal:6', 'inventory_value' => 'decimal:6', 'average_cost' => 'decimal:6', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'material_unit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class);
    }
}
