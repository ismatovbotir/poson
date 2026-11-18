<?php

namespace App\Support\Site;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Data cache of the home page (plan 34): one payload per locale, plain arrays
 * only (HC-2), under the key home:{locale}:s{SCHEMA}:v{version}:{deploy}. Raise SCHEMA
 * whenever a cached DTO changes its toArray()/fromArray() shape (HC-6).
 *
 *  - The locale comes from the app (already validated by SetLocale), never from
 *    a header or the raw URL, and anything outside the supported list is built
 *    without touching the cache (HC-1).
 *  - Every write path calls bump(): a new version makes the old key unreachable
 *    (it expires by TTL). bump() runs after the commit, so a rolled-back
 *    transaction changes nothing and a rebuild inside a transaction can only
 *    write to a dead key (HC-5).
 *  - TTL: at most 4 hours, cut at the next banner starts_at / ends_at and the
 *    next scheduled news post, so a time-bound item appears and disappears on
 *    time (HC-3). Not a flexible cache: no stale serving (HC-10).
 *  - The deploy id changes with every frontend build (HC-6).
 *  - The URLs in the payload are built on the canonical APP_URL, never on the
 *    request Host (HC-1).
 *
 * CSRF, the session, consent and the lead form are outside the payload (HC-9).
 */
class HomeCache
{
    public const MAX_TTL = 14400;

    /**
     * Shape of the cached payload: raise it whenever a DTO or enum used by the
     * home page changes, so old entries are never read with the new code.
     */
    public const SCHEMA = 2;

    private const FILES_MEMO = 'home.cache.deploy-files';

    public const VERSION_KEY = 'home:version';

    /**
     * The current version (created on first use; an evicted key gets a fresh
     * random one, so an old key is never revived).
     */
    public static function version(): string
    {
        return (string) Cache::rememberForever(self::VERSION_KEY, fn (): string => Str::lower(Str::random(10)));
    }

    /**
     * Invalidate every locale's payload, after the surrounding transaction commits.
     */
    public static function bump(): void
    {
        DB::afterCommit(fn () => Cache::forever(self::VERSION_KEY, Str::lower(Str::random(10))));
    }

    /**
     * Build id: a hash of the APP_DEPLOY_ID (when set, mixed in, never replacing
     * the rest), the cart switch (it decides the product card buttons) and the
     * modification times of the Vite manifest, the config cache and the site
     * lang files (copy shown in the cached services block). The file part is
     * computed once per request.
     */
    public static function deployId(): string
    {
        $files = app()->bound(self::FILES_MEMO) ? app(self::FILES_MEMO) : null;

        if ($files === null) {
            $parts = [];

            foreach ([
                public_path('build/manifest.json'),
                public_path('build/.vite/manifest.json'),
                base_path('bootstrap/cache/config.php'),
                ...array_map(fn (string $locale): string => lang_path($locale.'/site.php'), ['uz', 'ru', 'en']),
            ] as $file) {
                $parts[] = is_file($file) ? filemtime($file).':'.filesize($file) : '-';
            }

            $files = implode('|', $parts);
            app()->instance(self::FILES_MEMO, $files);
        }

        return substr(md5(implode('|', [(string) config('app.deploy_id'), 'cart:'.(int) (bool) config('site.cart_enabled'), $files])), 0, 8);
    }

    public static function key(string $locale): string
    {
        return 'home:'.$locale.':s'.self::SCHEMA.':v'.self::version().':'.self::deployId();
    }

    /**
     * Drop the cached payload of a locale (a payload that no longer hydrates).
     */
    public static function forget(string $locale): void
    {
        Cache::forget(self::key($locale));
    }

    /**
     * The payload of the locale, from the cache or built by $build (plain
     * arrays). An unsupported locale is built every time and never stored.
     *
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function remember(string $locale, callable $build): array
    {
        if (! in_array($locale, (array) config('app.supported_locales', []), true)) {
            return self::withCanonicalRoot($build);
        }

        $key = self::key($locale);
        $payload = Cache::get($key);

        if (is_array($payload)) {
            return $payload;
        }

        $payload = self::withCanonicalRoot($build);

        Cache::put($key, $payload, self::expiry());

        return $payload;
    }

    /**
     * The earliest of: 4 hours, the next banner boundary, the next scheduled news post.
     */
    public static function expiry(): CarbonInterface
    {
        $until = now()->addSeconds(self::MAX_TTL);

        foreach ([BannerDirectory::nextBoundary(), NewsDirectory::nextScheduledAt()] as $boundary) {
            if ($boundary !== null && $boundary->greaterThan(now()) && $boundary->lessThan($until)) {
                $until = Carbon::instance($boundary);
            }
        }

        return $until->greaterThan(now()->addSecond()) ? $until : now()->addSecond();
    }

    /**
     * Run $build on the canonical APP_URL root and scheme, so route() / asset()
     * never embed the request Host or scheme (HC-1).
     *
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    protected static function withCanonicalRoot(callable $build): array
    {
        return CanonicalHost::using($build);
    }

    /**
     * Bump on every save, delete, restore and force-delete of the given models.
     *
     * @param  list<class-string>  $models
     */
    public static function bumpOnModelEvents(array $models): void
    {
        foreach ($models as $model) {
            foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
                Event::listen('eloquent.'.$event.': '.$model, fn () => self::bump());
            }
        }
    }
}
