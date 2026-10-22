<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Published crawler IP ranges, stored by RefreshBotIpRanges in
 * config('analytics.verification.ranges_path') (storage/app/bot-ranges.json):
 *
 *   {"updated_at": "...", "bots": {"gptbot": ["132.196.86.0/24", ...], ...}}
 */
class BotIpRanges
{
    /**
     * @var array{updated_at: string|null, bots: array<string, list<string>>}|null
     */
    protected ?array $data = null;

    /**
     * CIDRs of one bot ([] when none are stored yet).
     *
     * @return list<string>
     */
    public function for(string $botKey): array
    {
        return $this->all()['bots'][$botKey] ?? [];
    }

    public function contains(string $botKey, string $ip): bool
    {
        $ranges = $this->for($botKey);
        $ip = IpAddress::normalize($ip);

        return $ranges !== [] && $ip !== null && IpUtils::checkIp($ip, $ranges);
    }

    /**
     * When the ranges were last downloaded, or null when never.
     */
    public function updatedAt(): ?CarbonImmutable
    {
        $updatedAt = $this->all()['updated_at'];

        try {
            return $updatedAt === null ? null : CarbonImmutable::parse($updatedAt);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Missing, unreadable or older than config analytics.verification.ranges_max_age_days.
     */
    public function isStale(): bool
    {
        $updatedAt = $this->updatedAt();
        $maxAge = (int) config('analytics.verification.ranges_max_age_days', 8);

        return ! is_file($this->path()) || $updatedAt === null || $updatedAt->lessThan(now()->subDays($maxAge));
    }

    /**
     * Forget what was read (the file changed on disk).
     */
    public function forget(): void
    {
        $this->data = null;
    }

    /**
     * @return array{updated_at: string|null, bots: array<string, list<string>>}
     */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = $this->path();
        $decoded = is_file($path) ? json_decode((string) File::get($path), true) : null;
        $bots = [];

        foreach ((array) ($decoded['bots'] ?? []) as $key => $cidrs) {
            $bots[(string) $key] = array_values(array_filter((array) $cidrs, fn (mixed $cidr): bool => is_string($cidr) && static::isCidr($cidr)));
        }

        return $this->data = [
            'updated_at' => is_string($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : null,
            'bots' => $bots,
        ];
    }

    /**
     * Replace the stored ranges (atomic write).
     *
     * @param  array<string, list<string>>  $bots
     */
    public function save(array $bots): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));

        $data = ['updated_at' => now()->toIso8601String(), 'bots' => $bots];
        $temporary = $path.'.'.getmypid().'.tmp';
        File::put($temporary, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::move($temporary, $path);

        $this->data = $data;
    }

    public function path(): string
    {
        return (string) config('analytics.verification.ranges_path', storage_path('app/bot-ranges.json'));
    }

    /**
     * "1.2.3.0/24", "2001:db8::/32" or a single address. Prefixes broader
     * than config analytics.verification.min_prefix (/8 IPv4, /16 IPv6) are
     * rejected: a broken or hostile list must not "verify" half the internet.
     */
    public static function isCidr(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $v6 = str_contains((string) $address, ':');
        $max = $v6 ? 128 : 32;
        $min = (int) config('analytics.verification.min_prefix.'.($v6 ? 'ipv6' : 'ipv4'), $v6 ? 16 : 8);

        return ctype_digit($bits) && (int) $bits <= $max && (int) $bits >= $min;
    }
}
