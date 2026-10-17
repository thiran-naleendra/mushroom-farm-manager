<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PurchaseAmounts
{
    public static function money(mixed $value): BigDecimal
    {
        return BigDecimal::of(is_float($value) ? sprintf('%.2F', $value) : (string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    public static function summary(Purchase $purchase): array
    {
        $credits = self::money(PurchaseReturn::whereIn('purchase_line_id', $purchase->lines()->select('id'))->sum('credit'));
        $paid = self::money($purchase->payments()->where('type', 'payment')->sum('amount'));
        $refunds = self::money($purchase->payments()->where('type', 'refund')->sum('amount'));

        return self::fromTotals($purchase->total, $credits, $paid, $refunds);
    }

    public static function fromTotals(mixed $total, mixed $credits, mixed $paid, mixed $refunds): array
    {
        $credits = self::money($credits ?? 0);
        $paid = self::money($paid ?? 0);
        $refunds = self::money($refunds ?? 0);
        $net = self::money($total)->minus($credits);
        $balance = $net->minus($paid)->plus($refunds);
        $status = $balance->isNegative() ? 'credit_due' : ($balance->isZero() ? 'paid' : ($paid->isGreaterThan($refunds) ? 'partial' : 'unpaid'));

        return ['returns' => (string) $credits, 'paid' => (string) $paid, 'refunds' => (string) $refunds, 'net' => (string) $net, 'balance' => (string) $balance, 'payment_status' => $status];
    }
}
