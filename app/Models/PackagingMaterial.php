<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class PackagingMaterial extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'total_cost' => 'decimal:6'];
    }
}
