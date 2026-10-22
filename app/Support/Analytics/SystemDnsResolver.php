<?php

namespace App\Support\Analytics;

/**
 * DnsResolver on PHP's resolver functions (system resolver timeouts apply;
 * used only by the nightly verification job, never during a page request).
 */
class SystemDnsResolver implements DnsResolver
{
    public function reverse(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $host = @gethostbyaddr($ip);

        return is_string($host) && $host !== '' && $host !== $ip ? strtolower(rtrim($host, '.')) : null;
    }

    public function forward(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
