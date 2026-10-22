<?php

namespace App\Support\Analytics;

/**
 * IP helpers for crawler records: an IPv4-mapped IPv6 address
 * (::ffff:a.b.c.d, as dual-stack servers report IPv4 clients) is treated as
 * the plain IPv4 address, so packing, range checks and DNS all agree.
 */
final class IpAddress
{
    /**
     * Canonical text form, or null when not an IP.
     */
    public static function normalize(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // ::ffff:0:0/96 → the embedded IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop($packed);
    }

    /**
     * Packed form for bot_hits.ip (4 or 16 bytes), or null.
     */
    public static function pack(?string $ip): ?string
    {
        $ip = self::normalize($ip);

        return $ip === null ? null : (string) inet_pton($ip);
    }

    /**
     * Text form of a packed address (mapped IPv6 unwrapped), or null.
     */
    public static function unpack(mixed $packed): ?string
    {
        if (! is_string($packed) || ! in_array(strlen($packed), [4, 16], true)) {
            return null;
        }

        $ip = @inet_ntop($packed);

        return $ip === false ? null : self::normalize($ip);
    }
}
