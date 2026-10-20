<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class LocaleUrl
{
    /**
     * Query parameters that identify a distinct page and survive in clean
     * (canonical / hreflang) URLs. Everything else (utm_*, filters, sort) is dropped.
     */
    public const CLEAN_QUERY_KEYS = ['page'];

    /**
     * URL of the current page in another locale, for the language switcher.
     * Route parameters and the full query string are kept.
     *
     * Falls back to the localized home page when the current route is not a
     * localized named route (e.g. error pages, admin, unknown routes).
     */
    public static function switch(string $locale): string
    {
        [$url, $query] = static::localizedRouteAndQuery($locale);

        return static::withQuery($url, $query);
    }

    /**
     * Clean URL of the current page in a locale, for canonical and hreflang:
     * only "page" (when > 1) is kept from the query string.
     */
    public static function clean(string $locale): string
    {
        [$url, $query] = static::localizedRouteAndQuery($locale);

        $page = $query['page'] ?? null;
        $clean = is_string($page) && ctype_digit($page) && (int) $page > 1
            ? ['page' => (int) $page]
            : [];

        return static::withQuery($url, $clean);
    }

    /**
     * Clean URL of the current page for every supported locale (hreflang alternates).
     *
     * @return array<string, string>
     */
    public static function alternates(): array
    {
        $urls = [];

        foreach (config('app.supported_locales') as $locale) {
            $urls[$locale] = static::clean($locale);
        }

        return $urls;
    }

    /**
     * Clean URL of the current page in the current locale.
     */
    public static function canonical(): string
    {
        return static::clean(app()->getLocale());
    }

    /**
     * Default site locale: the configured app locale (APP_LOCALE) when it is
     * supported, else the first supported one.
     *
     * Reads "app.default_locale" because app()->setLocale() overwrites
     * "app.locale" with the request locale.
     */
    public static function defaultLocale(): string
    {
        $supported = config('app.supported_locales', []);
        $configured = config('app.default_locale', config('app.locale'));

        if (is_string($configured) && in_array($configured, $supported, true)) {
            return $configured;
        }

        return $supported[0] ?? (string) config('app.fallback_locale');
    }

    /**
     * Current page URL in a locale (without query) plus the current query string.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected static function localizedRouteAndQuery(string $locale): array
    {
        $fallback = route('site.home', ['locale' => $locale]);

        [$route, $query] = static::currentRouteAndQuery();

        if ($route === null || $route->getName() === null || ! in_array('locale', $route->parameterNames(), true)) {
            return [$fallback, []];
        }

        // Only real URI parameters: Route::view() etc. store "view"/"status" as defaults.
        $parameters = array_merge(
            Arr::only($route->parameters(), $route->parameterNames()),
            ['locale' => $locale],
        );

        try {
            return [route($route->getName(), $parameters), $query];
        } catch (Throwable) {
            return [$fallback, []];
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected static function withQuery(string $url, array $query): string
    {
        return $query === [] ? $url : $url.'?'.Arr::query($query);
    }

    /**
     * Resolve the page route, also on Livewire update requests where the
     * current request is the Livewire endpoint instead of the page.
     *
     * @return array{0: ?Route, 1: array<string, mixed>}
     */
    protected static function currentRouteAndQuery(): array
    {
        $request = request();

        if (! Livewire::isLivewireRequest()) {
            return [$request->route(), $request->query()];
        }

        $path = '/'.ltrim(Livewire::originalPath(), '/');
        $query = static::queryFromReferer($request, $path);

        try {
            $pageRequest = Request::create($path, 'GET', $query);
            $route = app('router')->getRoutes()->match($pageRequest);
        } catch (HttpException) {
            return [null, []];
        }

        return [$route, $query];
    }

    /**
     * Livewire only remembers the page path, so take the query string from
     * the Referer when it points to the same page.
     *
     * @return array<string, mixed>
     */
    protected static function queryFromReferer(Request $request, string $path): array
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || '/'.ltrim((string) parse_url($referer, PHP_URL_PATH), '/') !== $path) {
            return [];
        }

        parse_str((string) parse_url($referer, PHP_URL_QUERY), $query);

        return $query;
    }
}
