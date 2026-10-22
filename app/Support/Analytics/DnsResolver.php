<?php

namespace App\Support\Analytics;

/**
 * DNS lookups for crawler verification (bound to SystemDnsResolver; tests
 * swap in a fake so no real DNS is queried).
 */
interface DnsResolver
{
    /**
     * Host name of the IP's PTR record, or null when there is none.
     */
    public function reverse(string $ip): ?string;

    /**
     * IPv4 and IPv6 addresses the host resolves to.
     *
     * @return list<string>
     */
    public function forward(string $host): array;
}
