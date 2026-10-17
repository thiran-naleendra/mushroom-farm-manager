<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutablePurchaseRecord
{
    protected static function bootImmutablePurchaseRecord(): void
    {
        static::updating(fn () => throw new LogicException('Original purchase records cannot be rewritten.'));
        static::deleting(fn () => throw new LogicException('Purchase history cannot be deleted.'));
    }
}
