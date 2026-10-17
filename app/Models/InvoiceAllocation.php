<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class InvoiceAllocation extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:6'];
    }
}
