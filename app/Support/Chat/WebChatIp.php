<?php

namespace App\Support\Chat;

use App\Support\Http\LivewireThrottle;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The website chat's IP hash (plan 28 C5, W-4, WC-17): HMAC-SHA256 of the
 * request IP in LivewireThrottle::ipKey() form (IPv6 as its /64), keyed with
 * a random salt of the day of its own ("chat:ip-salt:{Ymd}", about 48 hours,
 * in the analytics salt store), never the analytics salt, so the two hashes
 * cannot be correlated. hash() (daily salt) is what chats store at rest;
 * a lookup of stored hashes uses hashes() = [today, yesterday], so the
 * 24 h ban cooldown survives midnight (WC-14). Limiter counters must not
 * reset at midnight either: they key on limiterKey(), the same HMAC with a
 * monthly salt ("chat:ip-salt:m:{Ym}", about 40 days), never the raw IP.
 * Used for limits and the per-IP monitoring count only;
 * never shown anywhere, nulled after 30 days (chat:prune).
 */
final class WebChatIp
{
    public static function hash(?string $ip = null): string
    {
        $ip ??= request()->ip();

        return hash_hmac('sha256', LivewireThrottle::ipKey($ip), self::salt());
    }

    /**
     * Stored ip_hash values this IP may carry: today's and yesterday's salt.
     *
     * @return list<string>
     */
    public static function hashes(?string $ip = null): array
    {
        $ip = LivewireThrottle::ipKey($ip ?? request()->ip());

        return [
            hash_hmac('sha256', $ip, self::salt()),
            hash_hmac('sha256', $ip, self::salt(now()->subDay()->format('Ymd'))),
        ];
    }

    /** Stable for a month: the key of per-IP limiter counters. */
    public static function limiterKey(?string $ip = null): string
    {
        $ip ??= request()->ip();

        return hash_hmac('sha256', LivewireThrottle::ipKey($ip), self::salt(now()->format('Ym'), 'm:', 40 * 24));
    }

    private static function salt(?string $period = null, string $infix = '', int $hours = 48): string
    {
        $store = self::store();
        $key = 'chat:ip-salt:'.$infix.($period ?? now()->format('Ymd'));
        $salt = $store->get($key);

        if (! is_string($salt) || $salt === '') {
            $store->add($key, Str::random(64), now()->addHours($hours));
            $salt = (string) $store->get($key);
        }

        return $salt;
    }

    private static function store(): Repository
    {
        return Cache::store(config('analytics.salt_store'));
    }
}
