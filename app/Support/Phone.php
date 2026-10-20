<?php

namespace App\Support;

/**
 * Uzbek phone numbers: normalized to E.164 "+998XXXXXXXXX".
 */
class Phone
{
    public const COUNTRY_CODE = '998';

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
     * Human format "+998 (90) 123-45-67". Invalid input is returned unchanged.
     */
    public static function display(?string $phone): string
    {
        $normalized = static::normalize($phone);

        if ($normalized === null) {
            return (string) $phone;
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
