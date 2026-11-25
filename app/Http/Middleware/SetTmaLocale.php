<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "{locale}" of a Mini App route (plan 20 §8): like SetLocale, but
 * without the site's "locale" cookie (path /, MA-5). An unsupported locale
 * is a 404.
 */
class SetTmaLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $locale = $route?->parameter('locale');

        if (! is_string($locale) || ! in_array($locale, (array) config('app.supported_locales'), true)) {
            abort(404);
        }

        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);
        $route->forgetParameter('locale');

        return $next($request);
    }
}
