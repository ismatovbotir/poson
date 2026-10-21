<?php

namespace App\Support\Settings;

/**
 * Formats of company setting values (plan 04 §4). Used by the admin forms
 * (regex rules) and by CompanySettings, which re-validates on read and
 * returns '' for anything that doesn't match, so a bad DB row never
 * reaches a page.
 */
final class SettingsFormat
{
    /**
     * GA4 measurement ID, e.g. "G-AB12CD34EF".
     */
    public const GA4 = '/^G-[A-Z0-9]{4,16}$/';

    /**
     * Yandex Metrika counter ID (digits only), e.g. "98765432".
     */
    public const METRIKA = '/^[1-9][0-9]{4,11}$/';

    /**
     * Content of <meta name="google-site-verification">.
     */
    public const VERIFICATION_GOOGLE = '/^[A-Za-z0-9_-]{20,100}$/';

    /**
     * Content of <meta name="yandex-verification"> (hex).
     */
    public const VERIFICATION_YANDEX = '/^[0-9a-f]{16,64}$/i';

    /**
     * Content of <meta name="msvalidate.01"> (32 hex characters).
     */
    public const VERIFICATION_BING = '/^[0-9A-F]{32}$/i';

    /**
     * Taxpayer identification number (STIR / ИНН): 9 digits.
     */
    public const INN = '/^[0-9]{9}$/';

    /**
     * VAT registration code: 12 digits.
     */
    public const VAT = '/^[0-9]{12}$/';

    /**
     * Bank code (MFO): 5 digits.
     */
    public const MFO = '/^[0-9]{5}$/';

    /**
     * Settlement account: 20 digits.
     */
    public const ACCOUNT = '/^[0-9]{20}$/';

    /**
     * Activity code (OKED): 5 digits.
     */
    public const OKED = '/^[0-9]{5}$/';

    /**
     * Time of day "HH:MM" (24 h).
     */
    public const TIME = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /**
     * Coordinate in decimal degrees, at most 8 decimals.
     */
    public const COORDINATE = '/^-?[0-9]{1,3}(\.[0-9]{1,8})?$/';

    /**
     * Days of the week for opening hours => schema.org day names.
     */
    public const DAYS = [
        'mo' => 'Monday',
        'tu' => 'Tuesday',
        'we' => 'Wednesday',
        'th' => 'Thursday',
        'fr' => 'Friday',
        'sa' => 'Saturday',
        'su' => 'Sunday',
    ];

    /**
     * Verification code formats per search engine.
     *
     * @return array<string, string>
     */
    public static function verificationPatterns(): array
    {
        return [
            'google' => self::VERIFICATION_GOOGLE,
            'yandex' => self::VERIFICATION_YANDEX,
            'bing' => self::VERIFICATION_BING,
        ];
    }

    /**
     * The trimmed value when it is a string matching the pattern, else ''.
     */
    public static function match(mixed $value, string $pattern): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        $value = trim((string) $value);

        return preg_match($pattern, $value) === 1 ? $value : '';
    }

    /**
     * Laravel "regex:" rule for a pattern (pass it inside an array of rules,
     * never in a pipe-separated string: patterns may contain "|").
     */
    public static function rule(string $pattern): string
    {
        return 'regex:'.$pattern;
    }
}
