<?php

namespace App\Services;

use App\Models\MaterialUnit;
use Illuminate\Validation\ValidationException;

class MaterialQuantity
{
    public const MAX = 999999999999999;

    public static function micros(string $value): int
    {
        if (! preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,6}))?$/D', $value, $parts)) {
            throw ValidationException::withMessages(['quantity' => 'Use up to nine whole digits and six decimal places.']);
        }
        $micros = (int) $parts[2] * 1000000 + (int) str_pad($parts[3] ?? '', 6, '0');

        return $parts[1] === '-' ? -$micros : $micros;
    }

    public static function decimal(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 1000000).'.'.str_pad((string) (abs($value) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function convert(string $quantity, MaterialUnit $from, MaterialUnit $to): int
    {
        if ($from->dimension !== $to->dimension || ($from->dimension === 'custom' && $from->id !== $to->id)) {
            throw ValidationException::withMessages(['material_unit_id' => 'Choose a unit compatible with this material.']);
        }
        $value = self::micros($quantity);
        $numerator = self::micros($from->factor);
        $denominator = self::micros($to->factor);
        $gcd = self::gcd($numerator, $denominator);
        $numerator = intdiv($numerator, $gcd);
        $denominator = intdiv($denominator, $gcd);
        $gcd = self::gcd(abs($value), $denominator);
        $value = intdiv($value, $gcd);
        $denominator = intdiv($denominator, $gcd);
        if ($denominator !== 1 || abs($value) > intdiv(self::MAX, $numerator)) {
            throw ValidationException::withMessages(['quantity' => 'This conversion exceeds the stock limit or six-decimal precision.']);
        }

        return $value * $numerator;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
