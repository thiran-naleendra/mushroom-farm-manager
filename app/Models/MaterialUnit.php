<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;

class MaterialUnit extends Model
{
    use CastsInventoryDecimals;

    protected $fillable = ['name', 'symbol', 'dimension', 'factor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'factor' => 'decimal:6'];
    }
}
