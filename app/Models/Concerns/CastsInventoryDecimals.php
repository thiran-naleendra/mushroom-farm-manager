<?php

namespace App\Models\Concerns;

trait CastsInventoryDecimals
{
    protected function asDecimal($value, $decimals)
    {
        // SQLite returns decimal columns as floats; preserve their declared scale.
        // MySQL supplies exact decimal strings, which pass through unchanged.
        return parent::asDecimal(is_float($value) ? sprintf('%.'.$decimals.'F', $value) : $value, $decimals);
    }
}
