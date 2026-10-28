<?php

namespace App\Support\Site;

use App\Support\Catalog\HttpsUrl;

/**
 * Banner links (plan 07 7c, K11; decision 2026-10-27): an internal path or
 * an external https URL.
 *
 * Internal: starts with a single "/" (never "//", a protocol-relative URL
 * to another host), at most HttpsUrl::MAX_LENGTH bytes, no whitespace,
 * control, format (\p{Cf}) characters or backslashes, no "." / ".."
 * segments (plain or percent-encoded), and never into the admin panel or
 * the Livewire endpoint (/admin, /livewire..., also percent-encoded). Stored
 * WITHOUT a locale prefix ("/catalog/scanners"): normalize() strips a
 * pasted "/uz", "/ru" or "/en" ("/ru" alone becomes "/"), and href()
 * prepends the visitor's locale on the site ("/ru/catalog/scanners").
 * External: a valid App\Support\Catalog\HttpsUrl, kept as entered; opened
 * with rel="noopener" on the site.
 */
class BannerLink
{
    /**
     * First path segments an internal link may not point to (after the locale prefix).
     */
    private const BLOCKED_PATH = '#^/(admin|livewire[^/?\#]*)(?=[/?\#]|$)#i';

    /**
     * The value to store: trimmed, an internal path without its locale
     * prefix, or null when empty.
     */
    public static function normalize(?string $value): ?string
    {
        $value = HttpsUrl::normalize($value);

        if ($value === null || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return $value;
        }

        return static::withoutLocale($value);
    }

    public static function isValid(?string $value): bool
    {
        return static::isInternal($value) || HttpsUrl::isValid($value);
    }

    /**
     * A path on this site ("/catalog/scanners"; a legacy "/uz/catalog/..." too).
     */
    public static function isInternal(?string $value): bool
    {
        if ($value === null || $value === '' || strlen($value) > HttpsUrl::MAX_LENGTH || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        if (! str_starts_with($value, '/') || str_starts_with($value, '//') || preg_match('/[\s\p{Cc}\p{Cf}\\\\]/u', $value) !== 0) {
            return false;
        }

        // "/ru//host" would become the protocol-relative "//host" once the prefix is stripped.
        $path = static::withoutLocale($value);

        if (str_starts_with($path, '//') || static::hasDotSegment($path)) {
            return false;
        }

        // The router matches the decoded path: "/%61dmin" is /admin too.
        return preg_match(self::BLOCKED_PATH, $path) === 0 && preg_match(self::BLOCKED_PATH, static::decoded($path)) === 0;
    }

    /**
     * A valid external https link (opens with rel="noopener").
     */
    public static function isExternal(?string $value): bool
    {
        return ! static::isInternal($value) && HttpsUrl::isValid($value);
    }

    /**
     * The href on the site: an internal path in the given locale
     * ("/catalog" → "/ru/catalog", "/" → "/ru"; a stored locale prefix is
     * replaced), an external link as stored; null when the link is not valid.
     */
    public static function href(?string $value, string $locale): ?string
    {
        if (static::isInternal($value)) {
            $path = static::withoutLocale((string) $value);

            return '/'.$locale.($path === '/' ? '' : (str_starts_with($path, '/?') || str_starts_with($path, '/#') ? substr($path, 1) : $path));
        }

        return HttpsUrl::isValid($value) ? $value : null;
    }

    /**
     * A "." or ".." segment in the path (also percent-encoded, "%2e%2e"):
     * browsers resolve them, so "/ru/..//evil.example.com/" would leave the
     * site and "/../admin" would reach the admin panel.
     */
    private static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', static::decoded($path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * The path part (before "?" / "#"), percent-decoded until stable (at most three rounds).
     */
    private static function decoded(string $path): string
    {
        $path = (string) preg_split('/[?#]/', $path, 2)[0];

        for ($round = 0; $round < 3; $round++) {
            $decoded = rawurldecode($path);

            if ($decoded === $path) {
                break;
            }

            $path = $decoded;
        }

        return $path;
    }

    /**
     * The path without leading locale prefixes ("/ru/en/catalog" → "/catalog", "/uz" → "/").
     */
    private static function withoutLocale(string $path): string
    {
        $locales = implode('|', array_map(fn (string $locale): string => preg_quote($locale, '#'), config('app.supported_locales', ['uz', 'ru', 'en'])));

        do {
            $previous = $path;
            $path = (string) preg_replace('#^/('.$locales.')(?=[/?\#]|$)#i', '', $path);
        } while ($path !== $previous && $path !== '');

        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $path;
    }
}
