<?php

namespace App\Support\Catalog;

/**
 * External https links entered in admin, e.g. a brand website, which for a
 * software brand is the partner / referral link of the "Try" button
 * (decision 2026-10-22). The single rule for SaveBrand, BrandForm
 * (App\Rules\HttpsLink) and Product::partnerUrl().
 *
 * Valid: an absolute https URL (scheme case-insensitive) with a domain host
 * (Unicode IDN or punycode; no IP literals), an optional port 1-65535, no
 * credentials, at most MAX_LENGTH bytes, and no whitespace, control,
 * format (\p{Cf}: zero-width, bidi overrides) characters or backslashes.
 * The value is stored and used exactly as entered (a partner's query
 * parameters and fragment are kept); only surrounding whitespace is trimmed.
 */
class HttpsUrl
{
    /**
     * Longest link in bytes (the brands.website column is 2048 characters).
     */
    public const MAX_LENGTH = 2048;

    /**
     * The value to store: trimmed, or null when empty.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '' || strlen($value) > self::MAX_LENGTH || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        if (preg_match('/[\s\p{Cc}\p{Cf}\\\\]/u', $value) !== 0) {
            return false;
        }

        if (! str_starts_with(strtolower($value), 'https://')) {
            return false;
        }

        // The authority is read by hand: parse_url() mangles multibyte (IDN) hosts.
        $authority = (string) preg_split('/[\/?#]/', substr($value, strlen('https://')), 2)[0];

        if (str_contains($authority, '@')) {
            return false;
        }

        $host = $authority;

        if (preg_match('/^(.*):(\d*)$/', $authority, $match) === 1 && ! str_starts_with($authority, '[')) {
            $port = $match[2] === '' ? 0 : (int) $match[2];

            if ($port < 1 || $port > 65535) {
                return false;
            }

            $host = $match[1];
        }

        return self::isDomain($host);
    }

    /**
     * Host of a valid link (without port, "www." kept), for display; null
     * when the link is not valid. Read like isValid(): parse_url() mangles
     * multibyte (IDN) hosts.
     */
    public static function host(?string $value): ?string
    {
        if (! static::isValid($value)) {
            return null;
        }

        $authority = (string) preg_split('/[\/?#]/', substr((string) $value, strlen('https://')), 2)[0];

        return (string) preg_replace('/:\d+$/', '', $authority);
    }

    /**
     * A domain name with at least two labels: letters (any script), digits
     * and hyphens; IP addresses and IPv6 literals are not partner links.
     */
    private static function isDomain(string $host): bool
    {
        if ($host === '' || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', rtrim($host, '.'));

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || mb_strlen($label) > 63 || preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}-]*[\p{L}\p{N}\p{M}])?$/u', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
