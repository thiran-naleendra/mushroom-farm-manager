<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class FinishedStockMovement extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:6'];
    }
}
