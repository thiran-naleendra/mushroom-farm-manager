<?php

namespace App\Models;

use App\Models\Concerns\CastsInventoryDecimals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use CastsInventoryDecimals, SoftDeletes;

    public const TYPES = ['grocery' => 'Grocery shop', 'restaurant' => 'Restaurant', 'wholesale' => 'Wholesale buyer', 'individual' => 'Individual customer'];

    protected $fillable = ['name', 'type', 'phone', 'address', 'email', 'credit_limit', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CustomerAccountEntry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
