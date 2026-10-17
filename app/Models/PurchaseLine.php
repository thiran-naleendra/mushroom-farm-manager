<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseLine extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'stock_quantity' => 'decimal:6', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'material_unit_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class)->withTrashed();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }
}
