<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class InvoiceReturnLine extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'credit' => 'decimal:2', 'restock' => 'boolean'];
    }
}
