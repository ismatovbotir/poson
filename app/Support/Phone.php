<?php

namespace App\Support;

/**
 * Phone numbers. Uzbek ones are normalized to E.164 "+998XXXXXXXXX"
 * (normalize()); numbers of any country to "+<digits>" (normalizeAny(),
 * plan 14 v2 §8 / §20, decision 2026-11-02).
 */
class Phone
{
    public const COUNTRY_CODE = '998';

    /**
     * Uzbek operator / area codes (first two digits of the 9-digit national number).
     * A "+" number of exactly 9 digits starting with one of them is a mistyped
     * Uzbek number ("+90 123 45 67"), never a foreign one.
     */
    private const UZ_NATIONAL_PREFIXES = [
        '33', '50', '55', '61', '62', '65', '66', '67', '69',
        '70', '71', '72', '73', '74', '75', '76', '77', '78', '79',
        '88', '90', '91', '93', '94', '95', '97', '98', '99',
    ];

    /**
     * Normalize "90 123 45 67", "(90) 123-45-67", "998901234567", "+998 90 123 45 67"
     * to "+998901234567". Returns null when the input is not an Uzbek number.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        // Only digits, spaces, dots, dashes, brackets and one leading "+".
        if ($phone === '' || ! preg_match('/^\+?[\d\s().\-]+$/', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 9 && ! str_starts_with($phone, '+')) {
            $digits = self::COUNTRY_CODE.$digits;
        }

        // Country code, then a 2-digit operator/area code that never starts with 0.
        if (! preg_match('/^'.self::COUNTRY_CODE.'[1-9]\d{8}$/', $digits)) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Any country (TG-28): the Uzbek forms exactly as normalize() gives them,
     * otherwise only an explicit international number: one leading "+" or "00",
     * 8-15 digits, first digit 1-9, never a result starting with 998 (a
     * malformed Uzbek number stays invalid instead of becoming "foreign").
     * Input without + / 00 that is not Uzbek is ambiguous and returns null.
     */
    public static function normalizeAny(?string $phone): ?string
    {
        $uzbek = self::normalize($phone);

        if ($uzbek !== null || $phone === null) {
            return $uzbek;
        }

        $phone = trim($phone);

        if ($phone === '' || ! preg_match('/^\+?[\d\s().\-]+$/', $phone)) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '+')) {
            // digits as typed after the plus
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } else {
            return null;
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits) || str_starts_with($digits, self::COUNTRY_CODE)) {
            return null;
        }

        if (strlen($digits) === 9 && in_array(substr($digits, 0, 2), self::UZ_NATIONAL_PREFIXES, true)) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Whether the number is an Uzbek (+998) one, in any input format.
     */
    public static function isUzbek(?string $phone): bool
    {
        return self::normalize($phone) !== null;
    }

    /**
     * Whether the number is valid but not Uzbek (marker "non-UZ" in staff alerts and screens).
     */
    public static function isForeign(?string $phone): bool
    {
        $any = self::normalizeAny($phone);

        // normalizeAny() never returns a foreign +998 result, so +998 here means Uzbek.
        return $any !== null && ! str_starts_with($any, '+'.self::COUNTRY_CODE);
    }

    /**
     * Non-geographic and premium country codes that are never accepted (TG-41; config site.reserved_phone_prefixes).
     */
    public static function isReserved(string $normalized): bool
    {
        foreach ((array) config('site.reserved_phone_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, '+'.$prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last 4 digits only ("••••4567"), for telling records apart without
     * showing the number. Null when there are fewer than 4 digits.
     */
    public static function maskLastFour(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 4 ? '••••'.substr($digits, -4) : null;
    }

    /**
     * Human format "+998 (90) 123-45-67"; other countries "+<digits>" (no grouping).
     * Invalid input is returned unchanged.
     */
    public static function display(?string $phone): string
    {
        $normalized = static::normalize($phone);

        if ($normalized === null) {
            return static::normalizeAny($phone) ?? (string) $phone;
        }

        return sprintf(
            '+%s (%s) %s-%s-%s',
            substr($normalized, 1, 3),
            substr($normalized, 4, 2),
            substr($normalized, 6, 3),
            substr($normalized, 9, 2),
            substr($normalized, 11, 2),
        );
    }
}
