<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Telegram Mini App's own session (plan 20 §2 A3, MA-5): runs only in the
 * "tma" group, before StartSession. For this request the session and the
 * XSRF-TOKEN cookies are "poson_tma_session" / "XSRF-TOKEN" with path /tma,
 * Secure, HttpOnly (session), SameSite=None and Partitioned (CHIPS, so they
 * work inside Telegram Web's iframe); the lifetime is the Mini App idle time.
 * The site's "poson_session" and the admin session are never read or
 * written. The previous session config (and the store name) are restored
 * afterwards, so nothing leaks into a later request of the same process.
 */
class ConfigureTmaSession
{
    public const COOKIE = 'poson_tma_session';

    public const PATH = '/tma';

    public function handle(Request $request, Closure $next): Response
    {
        $previous = (array) config('session');

        config(['session' => array_merge($previous, [
            'cookie' => self::COOKIE,
            'path' => self::PATH,
            'domain' => null,
            'secure' => true,
            'http_only' => true,
            'same_site' => 'none',
            'partitioned' => true,
            'expire_on_close' => false,
            'lifetime' => (int) config('telegram.mini_app.idle_minutes', 120),
            // No session garbage collection from Mini App requests (security review L4): the site's requests do it.
            'lottery' => [0, 100],
        ])]);

        // A store resolved earlier in this process keeps its name otherwise.
        app('session')->driver()->setName(self::COOKIE);

        try {
            return $next($request);
        } finally {
            config(['session' => $previous]);
            app('session')->driver()->setName((string) ($previous['cookie'] ?? 'laravel-session'));
        }
    }
}
