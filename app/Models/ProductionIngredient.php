<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class ProductionIngredient extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipe_quantity' => 'decimal:6', 'quantity' => 'decimal:6', 'stock_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6'];
    }
}
