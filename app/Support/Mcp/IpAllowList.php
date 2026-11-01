<?php

namespace App\Support\Mcp;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional per-token IP allow-list of MCP tokens (plan 13 C14): up to MAX
 * entries, each an IPv4 / IPv6 address or CIDR range. Checked after the
 * token hash matched, against $request->ip() (which honours only the
 * proxies in TRUSTED_PROXIES, so a spoofed X-Forwarded-For can't pass).
 * An empty list allows every address.
 */
final class IpAllowList
{
    public const MAX = 10;

    /**
     * Split admin input (lines, commas or spaces) into trimmed, unique entries.
     *
     * @return list<string>
     */
    public static function parse(string|array|null $input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map(fn ($part): string => strtolower(trim((string) $part)), $parts ?: []))));
    }

    /**
     * A single IPv4 / IPv6 address or a CIDR range with a valid prefix length.
     */
    public static function isValidEntry(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /**
     * @param  list<string>|null  $allowed
     */
    public static function allows(?array $allowed, ?string $ip): bool
    {
        if ($allowed === null || $allowed === []) {
            return true;
        }

        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, $allowed);
    }
}
