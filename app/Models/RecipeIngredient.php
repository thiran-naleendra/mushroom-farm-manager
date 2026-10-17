<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    use CastsInventoryDecimals;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'material_unit_id');
    }
}
