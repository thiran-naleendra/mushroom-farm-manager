<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceLine extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function saleDescription(): string
    {
        return $this->type === 'packaged' ? $this->pack_size_grams.'g Pack × '.(int) $this->quantity : $this->quantity.' '.$this->unit;
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'selling_price' => 'decimal:2', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoiceAllocation::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturnLine::class);
    }
}
