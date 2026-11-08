<?php

namespace App\Support\Chat;

use App\Models\Page;
use App\Support\Site\CanonicalHost;
use Throwable;

/**
 * Where a "link" rule or button may point (plan 28, CB-6).
 *
 *  - A named site target from the fixed allow-list TARGETS (resolved with
 *    route() at render time, so it follows route changes), or "page" with the
 *    slug of an active static page. Any editor with chatbot.rules may use these.
 *  - An absolute external URL, Admin only (checked by SaveChatRule): https,
 *    host = the site's own host (or its www twin) or one listed in
 *    config('chatbot.link_hosts'), no user info, no odd port, no control
 *    characters, no backslash, no "//" start, at most URL_MAX bytes.
 *
 * Both are validated again every time a reply is built (resolve()), so a
 * stored value that went bad (page unpublished, host removed) is dropped
 * instead of sent. String functions only.
 */
final class LinkTargets
{
    public const URL_MAX = 500;

    /**
     * Allow-listed target => site route name.
     *
     * @var array<string, string>
     */
    public const TARGETS = [
        'home' => 'site.home',
        'catalog' => 'site.catalog.index',
        'brands' => 'site.brands.index',
        'search' => 'site.search',
        'cart' => 'site.cart',
        'news' => 'site.news.index',
        'articles' => 'site.articles.index',
    ];

    /**
     * Target keys an editor can pick (the page target comes with a slug).
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        return [...array_keys(self::TARGETS), 'page'];
    }

    /**
     * The absolute URL for link params ({target, slug?} or {url}), or null
     * when they are not valid right now.
     *
     * @param  array<string, mixed>  $params
     */
    public static function resolve(array $params, string $locale): ?string
    {
        if (! in_array($locale, config('app.supported_locales'), true)) {
            return null;
        }

        $url = $params['url'] ?? null;

        if (is_string($url)) {
            return self::isSafeExternalUrl($url) ? $url : null;
        }

        $target = $params['target'] ?? null;

        if (! is_string($target)) {
            return null;
        }

        try {
            if ($target === 'page') {
                $slug = $params['slug'] ?? null;

                if (! is_string($slug) || $slug === '' || strlen($slug) > 100 || ! Page::query()->active()->where('slug', $slug)->exists()) {
                    return null;
                }

                return CanonicalHost::using(fn (): string => route('site.page', ['locale' => $locale, 'page' => $slug]));
            }

            $route = self::TARGETS[$target] ?? null;

            return $route === null ? null : CanonicalHost::using(fn (): string => route($route, ['locale' => $locale]));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether an absolute URL may be linked to (see class comment).
     */
    public static function isSafeExternalUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::URL_MAX || ! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        for ($i = 0, $length = strlen($url); $i < $length; $i++) {
            $ord = ord($url[$i]);

            if ($ord <= 32 || $ord === 127 || $url[$i] === '\\') {
                return false;
            }
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::allowedHosts(), true);
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = [];
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($own) && $own !== '') {
            $own = strtolower($own);
            $hosts[] = $own;
            $hosts[] = str_starts_with($own, 'www.') ? substr($own, 4) : 'www.'.$own;
        }

        foreach ((array) config('chatbot.link_hosts', []) as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
}
