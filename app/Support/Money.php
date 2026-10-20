<?php

namespace App\Support;

/**
 * Money is stored as integer tiyin (1 soʻm = 100 tiyin). Never use floats.
 */
class Money
{
    public const NBSP = "\u{00A0}";

    public const DEFAULT_CURRENCY = 'soʻm';

    /**
     * 125000000 tiyin -> "1 250 000 soʻm" (non-breaking spaces).
     * Tiyin are shown only when present: 12345 -> "123,45 soʻm".
     */
    public static function format(int $tiyin): string
    {
        $sign = $tiyin < 0 ? '-' : '';
        $absolute = abs($tiyin);

        $sums = number_format(intdiv($absolute, 100), 0, '', self::NBSP);
        $remainder = $absolute % 100;

        $amount = $remainder === 0 ? $sums : $sums.','.str_pad((string) $remainder, 2, '0', STR_PAD_LEFT);

        return $sign.$amount.self::NBSP.static::currency();
    }

    /**
     * Null-safe variant for nullable prices ("price on request" is decided by the caller).
     */
    public static function formatOrNull(?int $tiyin): ?string
    {
        return $tiyin === null ? null : static::format($tiyin);
    }

    public static function currency(): string
    {
        return trans()->has('site.currency') ? __('site.currency') : self::DEFAULT_CURRENCY;
    }
}
