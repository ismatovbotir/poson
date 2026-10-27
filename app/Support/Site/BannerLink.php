<?php

namespace App\Support\Site;

use App\Support\Catalog\HttpsUrl;

/**
 * Banner links (plan 07 7c, K11): an internal path or an external https URL.
 *
 * Internal: starts with a single "/" (never "//", a protocol-relative URL
 * to another host), at most HttpsUrl::MAX_LENGTH bytes, no whitespace,
 * control, format (\p{Cf}) characters or backslashes. Kept exactly as
 * entered: adding the locale prefix ("/ru/catalog/...") is the editor's
 * choice. External: a valid App\Support\Catalog\HttpsUrl; opened with
 * rel="noopener" on the site.
 */
class BannerLink
{
    /**
     * The value to store: trimmed, or null when empty.
     */
    public static function normalize(?string $value): ?string
    {
        return HttpsUrl::normalize($value);
    }

    public static function isValid(?string $value): bool
    {
        return static::isInternal($value) || HttpsUrl::isValid($value);
    }

    /**
     * A path on this site ("/uz/catalog/scanners").
     */
    public static function isInternal(?string $value): bool
    {
        if ($value === null || $value === '' || strlen($value) > HttpsUrl::MAX_LENGTH || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        return str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && preg_match('/[\s\p{Cc}\p{Cf}\\\\]/u', $value) === 0;
    }

    /**
     * A valid external https link (opens with rel="noopener").
     */
    public static function isExternal(?string $value): bool
    {
        return ! static::isInternal($value) && HttpsUrl::isValid($value);
    }
}
