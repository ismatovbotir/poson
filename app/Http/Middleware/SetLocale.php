<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const COOKIE = 'locale';

    /**
     * Cookie lifetime in minutes (one year).
     */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Apply the "{locale}" route parameter to the application.
     *
     * Also registered as Livewire persistent middleware, so it runs again on
     * Livewire update requests with the original page route.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $locale = $route?->parameter('locale');
        $supported = config('app.supported_locales', []);

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            abort(404);
        }

        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);

        // Controllers and Livewire components don't receive the locale argument.
        $route->forgetParameter('locale');

        if ($request->cookie(self::COOKIE) !== $locale) {
            Cookie::queue(self::COOKIE, $locale, self::COOKIE_MINUTES);
        }

        return $next($request);
    }
}
