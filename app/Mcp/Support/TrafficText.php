<?php

namespace App\Mcp\Support;

/**
 * Cleans crawler / visitor controlled traffic values for MCP output (plan 13
 * M2-S16): paths lose their query string and fragment, referrers are reduced
 * to the host. Both then go through UntrustedText.
 */
final class TrafficText
{
    public static function path(mixed $path): ?string
    {
        if (! is_scalar($path)) {
            return null;
        }

        $path = (string) preg_replace('/[?#].*$/su', '', (string) $path);

        return UntrustedText::clean($path);
    }

    public static function host(mixed $referrer): ?string
    {
        if (! is_scalar($referrer)) {
            return null;
        }

        $value = trim((string) $referrer);

        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?? '');
        } else {
            $value = (string) preg_replace('/[\/?#:].*$/su', '', $value);
        }

        return UntrustedText::clean(mb_strtolower($value));
    }
}
