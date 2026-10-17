<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'stock_quantity' => 'decimal:6', 'credit' => 'decimal:2', 'carrying_value' => 'decimal:6', 'price_variance' => 'decimal:6'];
    }
}
