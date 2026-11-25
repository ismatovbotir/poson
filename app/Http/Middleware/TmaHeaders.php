<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers of every Mini App response (plan 20 §3, MA-3, MA-16):
 * noindex, private no-store, no referrer, and a strict CSP that pins the
 * scripts to telegram-web-app.js and our own bundle, forbids inline script
 * and style (no style= attributes; Telegram's CSSOM theme variables still
 * work), keeps fetch() and form posts inside <origin>/tma/ and lets only
 * Telegram Web frame the app. No X-Frame-Options here (it would break
 * Telegram Web); everything else on the site sends DENY (DenyFraming, MA-4).
 * While Vite runs hot (local only) its dev server is added.
 *
 * Livewire's automatic asset injection is switched off for the request
 * (MA-18; a long-running process could otherwise inject it after a site
 * page rendered a component) and restored in terminate().
 */
class TmaHeaders
{
    public const TELEGRAM_SCRIPT = 'https://telegram.org/js/telegram-web-app.js';

    public const FRAME_ANCESTORS = 'https://web.telegram.org';

    public function handle(Request $request, Closure $next): Response
    {
        // terminate() runs on a fresh instance: the previous value travels with the request.
        $request->attributes->set('tma.inject_assets', (bool) config('livewire.inject_assets', true));
        config(['livewire.inject_assets' => false]);

        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', static::policy($request));
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->attributes->has('tma.inject_assets')) {
            config(['livewire.inject_assets' => (bool) $request->attributes->get('tma.inject_assets')]);
        }
    }

    public static function policy(Request $request): string
    {
        $origin = $request->getSchemeAndHttpHost();
        $hot = ! app()->isProduction() && Vite::isRunningHot() ? ' '.trim((string) file_get_contents(Vite::hotFile())) : '';

        return implode('; ', [
            "default-src 'self'",
            'script-src '.self::TELEGRAM_SCRIPT." 'self'".$hot,
            "style-src 'self'".$hot,
            "img-src 'self' data:",
            'connect-src '.$origin.'/tma/'.$hot,
            'form-action '.$origin.'/tma/',
            "frame-src 'none'",
            "worker-src 'none'",
            "manifest-src 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            'frame-ancestors '.self::FRAME_ANCESTORS,
        ]);
    }
}
