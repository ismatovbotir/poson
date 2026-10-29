<?php

namespace App\Support;

/**
 * VAT (QQS) included in every price (plan 06, plan 11 v2 §2): prices and
 * totals are VAT-inclusive; the VAT part is shown as "including VAT 12%".
 */
final class Vat
{
    /**
     * VAT rate, percent.
     */
    public const PERCENT = 12;

    /**
     * The VAT part of a VAT-inclusive amount: round(amount × 12 / 112), in
     * integer tiyin (half up, no floats).
     */
    public static function of(int $amount): int
    {
        $denominator = 100 + self::PERCENT;

        return intdiv($amount * self::PERCENT * 2 + $denominator, $denominator * 2);
    }
}
