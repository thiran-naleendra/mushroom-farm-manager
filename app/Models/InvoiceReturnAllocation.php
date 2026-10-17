<?php

namespace App\Models;

use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;

class InvoiceReturnAllocation extends Model
{
    use ImmutablePurchaseRecord;

    public $timestamps = false;

    protected $guarded = ['id'];
}
