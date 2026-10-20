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
     * URL of the current page in another locale (route params and query string kept).
     *
     * Falls back to the localized home page when the current route is not a
     * localized named route (e.g. error pages, admin, unknown routes).
     */
    public static function switch(string $locale): string
    {
        $fallback = route('site.home', ['locale' => $locale]);

        [$route, $query] = static::currentRouteAndQuery();

        if ($route === null || $route->getName() === null || ! in_array('locale', $route->parameterNames(), true)) {
            return $fallback;
        }

        // Only real URI parameters: Route::view() etc. store "view"/"status" as defaults.
        $parameters = array_merge(
            Arr::only($route->parameters(), $route->parameterNames()),
            ['locale' => $locale],
        );

        try {
            $url = route($route->getName(), $parameters);
        } catch (Throwable) {
            return $fallback;
        }

        return $query === [] ? $url : $url.'?'.Arr::query($query);
    }

    /**
     * Current page URL for every supported locale (hreflang alternates, switcher).
     *
     * @return array<string, string>
     */
    public static function alternates(): array
    {
        $urls = [];

        foreach (config('app.supported_locales') as $locale) {
            $urls[$locale] = static::switch($locale);
        }

        return $urls;
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
