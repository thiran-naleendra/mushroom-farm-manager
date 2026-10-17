<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MaterialMovement extends Model
{
    use CastsInventoryDecimals;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'input_quantity' => 'decimal:6', 'balance_after' => 'decimal:6', 'unit_cost' => 'decimal:2', 'value_change' => 'decimal:6', 'value_after' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements cannot be edited. Record a correcting adjustment.'));
        static::deleting(fn () => throw new LogicException('Stock movement history cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'material_unit_id');
    }
}
