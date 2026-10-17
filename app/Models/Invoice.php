<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Services\PurchaseAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Invoice extends Model
{
    use CastsInventoryDecimals;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seller_snapshot' => 'array', 'customer_snapshot' => 'array', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if (array_diff(array_keys($invoice->getDirty()), ['status', 'void_reason', 'voided_by', 'voided_at', 'updated_at'])) {
                throw new LogicException('Invoice prices and details are immutable. Return or void the invoice.');
            }
        });
        static::deleting(fn () => throw new LogicException('Invoice history cannot be deleted.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturn::class);
    }

    public function summary(?string $through = null): array
    {
        return PurchaseAmounts::fromTotals($this->total,
            $this->returns()->when($through, fn ($q) => $q->where('returned_on', '<=', $through))->sum('credit'),
            $this->payments()->where('type', 'payment')->when($through, fn ($q) => $q->where('paid_on', '<=', $through))->sum('amount'),
            $this->payments()->where('type', 'refund')->when($through, fn ($q) => $q->where('paid_on', '<=', $through))->sum('amount'));
    }
}
