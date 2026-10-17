<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use App\Models\Concerns\ImmutablePurchaseRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountEntry extends Model
{
    use CastsInventoryDecimals, ImmutablePurchaseRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
