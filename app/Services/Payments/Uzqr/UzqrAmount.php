<?php

namespace App\Services\Payments\Uzqr;

use InvalidArgumentException;

/**
 * UzQR amounts: a string in soʻm with a dot and two decimals ("100.01"),
 * built from integer tiyin with integer math only (condition 42).
 */
final class UzqrAmount
{
    public static function format(int $tiyin): string
    {
        if ($tiyin <= 0) {
            throw new InvalidArgumentException('A UzQR amount must be positive.');
        }

        return sprintf('%d.%02d', intdiv($tiyin, 100), $tiyin % 100);
    }

    /**
     * Tiyin of a soʻm string ("1234.5", "1234.50", "1234"), or null when it isn't one.
     */
    public static function toTiyin(string $value): ?int
    {
        if (preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/', trim($value), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }
}
