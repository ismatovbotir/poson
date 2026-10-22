<?php

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Cache;

/**
 * Checks that an IP really belongs to the crawler its User-Agent names:
 *
 *  - published ranges loaded for the bot: inside → genuine; outside → falls
 *    back to reverse DNS when the bot has rDNS domains, else spoofed;
 *  - reverse DNS: the PTR host ends with a vendor domain AND that host
 *    resolves back to the same IP (forward-confirmed), else spoofed.
 *
 * IPv4-mapped IPv6 addresses are checked as IPv4. Verdicts are cached per
 * bot + IP (config analytics.verification.cache_days). Every DNS query (the
 * reverse and the forward lookup each count as one) uses the daily budget
 * (max_lookups_per_day); when it is spent, or a bot can't be checked yet,
 * the verdict is null (try again later).
 */
class BotVerifier
{
    public function __construct(protected DnsResolver $resolver, protected BotIpRanges $ranges) {}

    public function verify(BotDefinition $bot, string $ip): ?bool
    {
        $ip = IpAddress::normalize($ip);

        if ($ip === null) {
            return false;
        }

        $cacheKey = "analytics:bot-ip:{$bot->key}:{$ip}";
        $cached = Cache::get($cacheKey);

        if (is_bool($cached)) {
            return $cached;
        }

        $verdict = $this->check($bot, $ip);

        if ($verdict !== null) {
            Cache::put($cacheKey, $verdict, now()->addDays((int) config('analytics.verification.cache_days', 7)));
        }

        return $verdict;
    }

    protected function check(BotDefinition $bot, string $ip): ?bool
    {
        if ($this->ranges->for($bot->key) !== []) {
            if ($this->ranges->contains($bot->key, $ip)) {
                return true;
            }

            return $bot->rdnsDomains !== [] ? $this->reverseDns($bot, $ip) : false;
        }

        if ($bot->rdnsDomains !== []) {
            return $this->reverseDns($bot, $ip);
        }

        // Ranges not downloaded yet (or a User-Agent-only bot): undecided.
        return null;
    }

    protected function reverseDns(BotDefinition $bot, string $ip): ?bool
    {
        if (! $this->takeLookup()) {
            return null;
        }

        $host = $this->resolver->reverse($ip);

        if ($host === null) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));
        $inDomain = false;

        foreach ($bot->rdnsDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $inDomain = true;

                break;
            }
        }

        if (! $inDomain) {
            return false;
        }

        if (! $this->takeLookup()) {
            return null;
        }

        foreach ($this->resolver->forward($host) as $address) {
            if (IpAddress::normalize($address) === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Use one DNS query from today's budget; false when it is spent.
     */
    protected function takeLookup(): bool
    {
        $key = 'analytics:dns-lookups:'.TrafficRecorder::today();
        $used = Cache::increment($key);

        if ($used === false || $used === 1) {
            Cache::put($key, 1, now()->addDays(2));
            $used = 1;
        }

        return $used <= (int) config('analytics.verification.max_lookups_per_day', 500);
    }
}
