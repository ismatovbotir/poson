<?php

namespace App\Support\Catalog;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Support\Site\HomeCache;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Cache of the unfiltered filter facets of a Mini App category (plan 45 B,
 * MA-49 / MA-50): one entry per (category, locale) under
 * tma:facets:{locale}:{category}:s{SCHEMA}:v{HomeCache::version()}:{deploy}:{labels}.
 *
 *  - Plain scalars and arrays only (cache.serializable_classes stays false):
 *    the attribute type is stored as its value and rebuilt with tryFrom();
 *    an entry that does not hydrate is rebuilt live and overwritten.
 *  - Invalidation reuses the home cache version (every catalog write path
 *    bumps it after the commit); TTL of TTL seconds is only a safety net.
 *  - The deploy id (HomeCache::deployId(): build, config cache, site lang)
 *    plus a fingerprint of the catalog lang files (unit labels) change the
 *    key whenever the copy behind the labels changes.
 *  - The caller passes the app locale (validated by tma.locale) and a
 *    category that passed the live CategoryTree::isVisible() check. An
 *    unsupported locale is built every time and never stored.
 *  - Filtered facets are never cached (the caller decides, see
 *    CategoryController::filters()).
 */
class CategoryFacetCache
{
    /**
     * Shape of the stored payload: raise it whenever CatalogQuery::facets()
     * changes its keys, so old entries are never read with the new code.
     */
    public const SCHEMA = 1;

    public const TTL = 21600;

    public const WARMED_KEY = 'tma:catalog:warmed';

    /**
     * Shared by the scheduled warm and the admin "Rebuild" button (MA-52).
     */
    public const LOCK_KEY = 'tma:catalog:warm-lock';

    public const LOCK_SECONDS = 300;

    private const LABELS_MEMO = 'tma.facet-cache.labels';

    public static function key(int $categoryId, string $locale): string
    {
        return 'tma:facets:'.$locale.':'.$categoryId.':s'.self::SCHEMA.':v'.HomeCache::version().':'.HomeCache::deployId().':'.self::labelsId();
    }

    public static function isCacheableLocale(string $locale): bool
    {
        return in_array($locale, (array) config('app.supported_locales', []), true);
    }

    /**
     * The facets of the category in the locale, from the cache or built by
     * $build (CatalogQuery::facets() of an unfiltered query).
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function remember(Category $category, string $locale, Closure $build): array
    {
        if (! self::isCacheableLocale($locale)) {
            return $build();
        }

        $key = self::key($category->id, $locale);
        $stored = Cache::get($key);

        if (is_array($stored) && ($facets = self::hydrate($stored)) !== null) {
            return $facets;
        }

        return self::refresh($category, $locale, $build, $key);
    }

    /**
     * Build the facets and store them (a warm, or a miss). Nothing is stored
     * for an unsupported locale or a payload that holds anything but scalars
     * and arrays.
     *
     * The key is taken BEFORE the build: a version bump while it runs (a
     * concurrent write) leaves the result under the old, unreachable key,
     * never under the new one with stale counts.
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function refresh(Category $category, string $locale, Closure $build, ?string $key = null): array
    {
        $cacheable = self::isCacheableLocale($locale);
        $key ??= $cacheable ? self::key($category->id, $locale) : null;
        $facets = $build();

        if ($cacheable && $key !== null && ($payload = self::dehydrate($facets)) !== null) {
            Cache::put($key, $payload, self::TTL);
        }

        return $facets;
    }

    public static function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
    }

    /**
     * The last warm: when, how many entries, how long it took.
     *
     * @return array{at: string, entries: int, ms: int}|null
     */
    public static function warmedAt(): ?array
    {
        $warmed = Cache::get(self::WARMED_KEY);

        if (! is_array($warmed) || ! is_string($warmed['at'] ?? null) || ! is_int($warmed['entries'] ?? null) || ! is_int($warmed['ms'] ?? null)) {
            return null;
        }

        return ['at' => $warmed['at'], 'entries' => $warmed['entries'], 'ms' => $warmed['ms']];
    }

    /**
     * @param  array{at: string, entries: int, ms: int}  $warmed
     */
    public static function markWarmed(array $warmed): void
    {
        Cache::forever(self::WARMED_KEY, $warmed);
    }

    /**
     * The facets as plain data: attribute types as their values. Null when
     * anything else in the payload is not a scalar or an array.
     *
     * @param  array<string, mixed>  $facets
     * @return array<string, mixed>|null
     */
    public static function dehydrate(array $facets): ?array
    {
        foreach ((array) ($facets['attributes'] ?? []) as $index => $facet) {
            if (! is_array($facet) || ! ($facet['type'] ?? null) instanceof AttributeType) {
                return null;
            }

            $facets['attributes'][$index]['type'] = $facet['type']->value;
        }

        return self::isPlain($facets) ? $facets : null;
    }

    /**
     * The stored payload with real AttributeType values, or null when it does
     * not have the facets shape (rebuilt live by the caller).
     *
     * @param  array<mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function hydrate(array $payload): ?array
    {
        foreach (['brands', 'stock', 'price', 'attributes'] as $group) {
            if (! is_array($payload[$group] ?? null)) {
                return null;
            }
        }

        foreach ($payload['attributes'] as $index => $facet) {
            $type = is_array($facet) && is_string($facet['type'] ?? null) ? AttributeType::tryFrom($facet['type']) : null;

            if ($type === null) {
                return null;
            }

            $payload['attributes'][$index]['type'] = $type;
        }

        return $payload;
    }

    private static function isPlain(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isPlain($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * Fingerprint of the catalog lang files (unit labels in the facets), not
     * covered by HomeCache::deployId(). Computed once per request.
     */
    private static function labelsId(): string
    {
        if (app()->bound(self::LABELS_MEMO)) {
            return app(self::LABELS_MEMO);
        }

        $parts = [];

        foreach ((array) config('app.supported_locales', []) as $locale) {
            $file = lang_path($locale.'/catalog.php');
            $parts[] = is_file($file) ? filemtime($file).':'.filesize($file) : '-';
        }

        $id = substr(md5(implode('|', $parts)), 0, 6);
        app()->instance(self::LABELS_MEMO, $id);

        return $id;
    }
}
