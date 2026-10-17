<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Purchase extends Model
{
    use CastsInventoryDecimals;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $purchase) {
            if (array_diff(array_keys($purchase->getDirty()), ['status', 'received_at', 'updated_at'])) {
                throw new \LogicException('Original purchase details cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Purchase history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'received_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function returns(): HasManyThrough
    {
        return $this->hasManyThrough(PurchaseReturn::class, PurchaseLine::class);
    }
}
