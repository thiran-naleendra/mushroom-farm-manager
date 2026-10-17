<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FreshQuantity
{
    public static function grams(string $kg): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/D', $kg, $parts)) {
            throw ValidationException::withMessages(['weight' => 'Use kilograms with up to three decimal places.']);
        }

        return (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    public static function kg(int $grams): string
    {
        return ($grams < 0 ? '-' : '').intdiv(abs($grams), 1000).'.'.str_pad((string) (abs($grams) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
