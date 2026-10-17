<?php

namespace App\Models;

use App\Services\FreshQuantity;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = ['customer', 'customer_id', 'amount_paid', 'sold_on', 'weight', 'price_per_kg', 'request_id', 'payload_hash', 'user_id'];

    protected static function booted(): void
    {
        static::updating(function (self $sale) {
            if ($sale->getOriginal('customer_id') || $sale->customer_id || $sale->getOriginal('request_id')) {
                throw new \LogicException('Linked customer sales cannot be rewritten. Preserve the account history.');
            }
        });
        static::deleting(function (self $sale) {
            if ($sale->customer_id || $sale->request_id) {
                throw new \LogicException('Linked customer sales cannot be deleted.');
            }
        });
        static::saving(function (self $sale) {
            $sale->weight_grams = FreshQuantity::grams((string) $sale->weight);
        });
    }
}
