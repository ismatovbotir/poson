<?php

namespace App\Support;

/**
 * Locale-aware numbers for the site: uz and ru write "1 250,5" (no-break
 * space between thousands, decimal comma), en writes "1,250.5". Decimals
 * stored as strings ("12.5000") keep their digits; trailing zeros go.
 */
class NumberFormat
{
    public static function format(int|float|string $number, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        [$thousands, $decimal] = $locale === 'en' ? [',', '.'] : [Money::NBSP, ','];

        $value = is_float($number) ? rtrim(rtrim(sprintf('%.4F', $number), '0'), '.') : trim((string) $number);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (! ctype_digit($integer) || ($fraction !== '' && ! ctype_digit($fraction))) {
            return (string) $number;
        }

        $grouped = strrev(implode(strrev($thousands), str_split(strrev($integer), 3)));

        return ($negative ? '-' : '').$grouped.($fraction !== '' ? $decimal.$fraction : '');
    }
}
