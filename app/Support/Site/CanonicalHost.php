<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\URL;

/**
 * Builds URLs on the configured site root (config('app.url')) instead of the
 * request host, for documents that are cached or read by crawlers
 * (sitemap.xml, robots.txt): a request with a foreign Host header must never
 * end up in them.
 */
class CanonicalHost
{
    /**
     * Run the callback with route()/url() forced to APP_URL, then restore the
     * request-based root and scheme.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(callable $callback): mixed
    {
        $root = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($root, PHP_URL_SCHEME);

        URL::forceRootUrl($root);
        URL::forceScheme(is_string($scheme) ? $scheme : null);

        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    /**
     * Hosts the app answers to: the APP_URL host and its "www." twin
     * (or the bare host when APP_URL is the www one).
     *
     * @return list<string>
     */
    public static function trustedHosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [];
        }

        $twin = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;

        return ['^'.preg_quote($host).'$', '^'.preg_quote($twin).'$'];
    }
}
