<?php

namespace App\Support\Mcp;

use App\Models\ApiToken;
use App\Models\McpCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Security alerts on MCP token use (plan 13 C10/C11/C14), called by
 * AuthenticateMcpToken after the hash matched and independent of the
 * throttled last_used write:
 * - first use of a token → alert (once);
 * - an IP or a client family (ClientFamily) not seen for the token in the
 *   last KNOWN_DAYS days → alert once per value, at most
 *   MAX_ALERTS_PER_HOUR per token. A value becomes "known" only when its
 *   alert was actually sent: one suppressed by the hourly cap stays
 *   "pending" (and unknown, even if mcp_calls has it) and alerts on its next
 *   use after the window. Known values are cached per token (keys hold the
 *   token id and a sha1 of the value, never the token); IPs also count as
 *   known when mcp_calls has them (index api_token_id, ip) and none is pending;
 * - a revoked token or a blocked IP → alert at most once per hour per token.
 */
final class McpTokenWatcher
{
    public const KNOWN_DAYS = 90;

    public const MAX_ALERTS_PER_HOUR = 10;

    public static function observe(ApiToken $token, Request $request): void
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $family = ClientFamily::of($userAgent);

        if ($token->last_used_at === null) {
            if (Cache::add(self::key($token, 'first-use', 'yes'), true, now()->addDays(self::KNOWN_DAYS))) {
                self::remember($token, 'ip', (string) $ip);
                self::remember($token, 'ua', $family);
                McpAlerts::firstUse($token, $ip, $userAgent);

                return;
            }
        }

        $newIp = $ip !== null && ! self::isKnownIp($token, $ip);
        $newClient = ! self::isKnownClient($token, $family);

        if ($newIp) {
            self::alertOrMarkPending($token, 'ip', $ip, fn () => McpAlerts::newIp($token, $ip, $userAgent));
        }

        if ($newClient) {
            self::alertOrMarkPending($token, 'ua', $family, fn () => McpAlerts::newClient($token, $ip, $userAgent));
        }
    }

    /**
     * A revoked token was presented (≤ 1 alert per hour per token).
     */
    public static function revokedUsed(ApiToken $token, Request $request): void
    {
        if (Cache::add(self::key($token, 'revoked-used', 'yes'), true, now()->addHour())) {
            McpAlerts::revokedUsed($token, $request->ip(), $request->userAgent());
        }
    }

    /**
     * A valid token from an address outside its allow-list (≤ 1 alert per hour per token).
     */
    public static function ipBlocked(ApiToken $token, Request $request): void
    {
        if (Cache::add(self::key($token, 'ip-blocked', 'yes'), true, now()->addHour())) {
            McpAlerts::ipBlocked($token, $request->ip(), $request->userAgent());
        }
    }

    private static function isKnownIp(ApiToken $token, string $ip): bool
    {
        if (Cache::has(self::key($token, 'pending-ip', $ip))) {
            return false;
        }

        return $token->last_used_ip === $ip
            || Cache::has(self::key($token, 'ip', $ip))
            || McpCall::query()->where('api_token_id', $token->id)->where('ip', $ip)->where('created_at', '>=', now()->subDays(self::KNOWN_DAYS))->exists();
    }

    private static function isKnownClient(ApiToken $token, string $family): bool
    {
        if (Cache::has(self::key($token, 'pending-ua', $family))) {
            return false;
        }

        return ($token->last_used_ua !== null && ClientFamily::of($token->last_used_ua) === $family)
            || Cache::has(self::key($token, 'ua', $family));
    }

    private static function remember(ApiToken $token, string $kind, string $value): void
    {
        Cache::put(self::key($token, $kind, $value), true, now()->addDays(self::KNOWN_DAYS));
    }

    /**
     * Alert within the hourly cap and remember the value; over the cap the
     * value stays pending so it alerts on a later use.
     */
    private static function alertOrMarkPending(ApiToken $token, string $kind, string $value, callable $alert): void
    {
        $sent = RateLimiter::attempt('mcp-alerts:'.$token->id, self::MAX_ALERTS_PER_HOUR, function () use ($alert): bool {
            $alert();

            return true;
        }, 3600);

        if ($sent) {
            Cache::forget(self::key($token, 'pending-'.$kind, $value));
            self::remember($token, $kind, $value);

            return;
        }

        Cache::put(self::key($token, 'pending-'.$kind, $value), true, now()->addDays(self::KNOWN_DAYS));
    }

    private static function key(ApiToken $token, string $kind, string $value): string
    {
        return 'mcp-watch:'.$token->id.':'.$kind.':'.sha1($value);
    }
}
