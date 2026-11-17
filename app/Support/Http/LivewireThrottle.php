<?php

namespace App\Support\Http;

use App\Models\User;
use App\Support\Analytics\IpAddress;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Limiter "livewire" for POST /livewire-{hash}/update (T7): every component
 * round trip of the site and the admin goes through that one route.
 *
 * - An active user with a staff role: 600 per minute, keyed by the user id.
 * - Everyone else (guests, customers, deactivated users): 180 per minute,
 *   keyed by the client IP resolved by TrustProxies ($request->ip()); an IPv6
 *   address counts as its /64 network. The key is never logged.
 * - Per-action limiters (lead form, checkout, uploads…) stay on top.
 *
 * Measured load (S7-6): /uz, a category page and a product page contain no
 * lazy, deferred, polled or island components, so a page view makes 0 update
 * requests; only interactions (cart add, filters, forms) do. 180 per minute
 * for a guest is far above 5x that.
 *
 * Website chat (plan 28 C5b, WC-11): the widget sends update requests only
 * after the visitor clicks the launcher (or right after the page for a chat
 * with recent staff replies); its poll runs in manager mode only, at most
 * 12 per minute per open tab, and handles a 429 quietly. Keep
 * GUEST_PER_MINUTE at 180 or more.
 */
final class LivewireThrottle
{
    public const STAFF_PER_MINUTE = 600;

    public const GUEST_PER_MINUTE = 180;

    public static function limit(Request $request): Limit
    {
        $user = $request->user();

        if ($user instanceof User && $user->isStaff()) {
            return Limit::perMinute(self::STAFF_PER_MINUTE)->by('lw:u:'.$user->id);
        }

        return Limit::perMinute(self::GUEST_PER_MINUTE)->by('lw:ip:'.self::ipKey($request->ip()));
    }

    /**
     * IPv4 as it is, IPv6 reduced to its /64 prefix (hex), unknown as "unknown".
     */
    public static function ipKey(?string $ip): string
    {
        $ip = IpAddress::normalize($ip);

        if ($ip === null) {
            return 'unknown';
        }

        $packed = (string) inet_pton($ip);

        return strlen($packed) === 16 ? bin2hex(substr($packed, 0, 8)).'::/64' : $ip;
    }
}
