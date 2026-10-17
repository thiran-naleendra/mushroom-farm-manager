<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Farm extends Model
{
    protected $fillable = ['owner_id', 'name', 'mode', 'currency', 'logo_path', 'address', 'phone', 'email', 'timezone', 'batch_tracking', 'qr_printing', 'grocery_sales', 'mushroom_varieties', 'fresh_stock_alert_kg'];

    protected $attributes = ['currency' => 'LKR', 'timezone' => 'Asia/Colombo', 'batch_tracking' => true, 'qr_printing' => true, 'grocery_sales' => false];

    protected function casts(): array
    {
        return ['batch_tracking' => 'boolean', 'qr_printing' => 'boolean', 'grocery_sales' => 'boolean', 'mushroom_varieties' => 'array'];
    }

    public function tracksBatches(): bool
    {
        return $this->isAdvanced() && $this->batch_tracking;
    }

    public function isAdvanced(): bool
    {
        return $this->mode === 'advanced';
    }

    public function printsQr(): bool
    {
        return $this->tracksBatches() && $this->qr_printing;
    }
}
