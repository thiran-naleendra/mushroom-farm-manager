<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use CastsInventoryDecimals;
    use SoftDeletes;

    public const DEFAULT_PACK_SIZE_GRAMS = 200;

    public const TYPES = ['packaged' => 'Packaged mushrooms (pcs)', 'fresh' => 'Fresh mushrooms by weight (optional)', 'cultivation_bag' => 'Mushroom cultivation bags'];

    public function scopeRetailFirst($query)
    {
        return $query->orderByRaw("CASE WHEN type = 'packaged' AND pack_size_grams = ? THEN 0 WHEN type = 'packaged' THEN 1 ELSE 2 END", [self::DEFAULT_PACK_SIZE_GRAMS])->orderBy('name');
    }

    protected $attributes = ['type' => 'packaged', 'unit' => 'g'];

    protected $fillable = ['name', 'sku', 'variety', 'type', 'weight', 'unit', 'selling_price', 'pack_size_grams', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'pack_size_grams' => 'integer', 'weight' => 'decimal:3', 'selling_price' => 'decimal:2'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinishedStockMovement::class);
    }
}
