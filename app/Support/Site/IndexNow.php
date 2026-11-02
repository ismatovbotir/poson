<?php

namespace App\Support\Site;

use App\Enums\NewsKind;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * IndexNow (plan 18): tells Bing / Yandex which public URLs appeared, changed
 * or disappeared. State lives in the cache store (no tables):
 * snapshot (sitemap locs of the last run), pending (changed record ids per
 * type), backlog (URLs beyond one request), log (last results, no URLs, no key).
 *
 * Everything is gated by isActive(): enabled in admin, a valid key, production,
 * a public https APP_URL and indexing open. Gate off: nothing is recorded or
 * sent. The key is read from Secrets only at the moment it is needed and is
 * never put into a job, a log, a cache value or an exception message.
 */
class IndexNow
{
    /**
     * Fixed, never configurable (no SSRF surface).
     */
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public const SNAPSHOT_KEY = 'indexnow:snapshot';

    public const PENDING_KEY = 'indexnow:pending';

    public const BACKLOG_KEY = 'indexnow:backlog';

    public const LOG_KEY = 'indexnow:log';

    public const INFLIGHT_KEY = 'indexnow:inflight';

    public const LOCK_KEY = 'indexnow:pending';

    public const INFLIGHT_SECONDS = 28800;

    public const PENDING_CAP = 5000;

    public const BACKLOG_CAP = 20000;

    public const MAX_URLS = 10000;

    public const LOG_LIMIT = 20;

    /**
     * @var list<string>
     */
    public const TYPES = ['product', 'category', 'brand', 'page', 'news_post'];

    /**
     * Whether the whole feature may record, send or answer the key URL.
     */
    public static function isActive(): bool
    {
        try {
            return app()->isProduction()
                && IntegrationRegistry::webhooksArePublic()
                && Secrets::get('indexnow.enabled') === true
                && self::key() !== null
                && app(CompanySettings::class)->isIndexingOpen();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The effective key when it is valid, else null.
     */
    public static function key(): ?string
    {
        $key = Secrets::get('indexnow.key');

        return is_string($key) && preg_match(IntegrationRegistry::INDEXNOW_KEY, $key) === 1 ? $key : null;
    }

    /**
     * Remember a changed model for the next flush. Called from the model's
     * "saved" / "deleted" hooks (changes are captured here, the id is stored
     * after the commit). Never throws.
     */
    public static function record(Model $model): void
    {
        try {
            $type = self::typeOf($model);

            if ($type === null || ! self::isActive()) {
                return;
            }

            $changed = array_values(array_diff(array_keys($model->getChanges()), ['updated_at']));
            $ignored = defined($model::class.'::INDEXNOW_IGNORED') ? $model::INDEXNOW_IGNORED : [];

            if ($changed !== [] && array_diff($changed, $ignored) === []) {
                return;
            }

            $id = (int) $model->getKey();

            DB::afterCommit(fn () => self::append($type, $id));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Add an id to the pending list (capped per type). A lock timeout or any
     * other failure is reported, never thrown (it runs after a commit).
     */
    public static function append(string $type, int $id): void
    {
        try {
            $lock = self::lock();
            $lock->block(3);

            try {
                $pending = self::pending();
                $ids = $pending[$type] ?? [];

                if (! in_array($id, $ids, true) && count($ids) < self::PENDING_CAP) {
                    $ids[] = $id;
                    $pending[$type] = $ids;
                    Cache::forever(self::PENDING_KEY, $pending);
                }
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, list<int>>
     */
    public static function pending(): array
    {
        $pending = Cache::get(self::PENDING_KEY);
        $clean = [];

        foreach (self::TYPES as $type) {
            $clean[$type] = is_array($pending) && is_array($pending[$type] ?? null) ? array_values(array_map('intval', $pending[$type])) : [];
        }

        return $clean;
    }

    /**
     * Read and clear the pending ids.
     *
     * @return array<string, list<int>>
     */
    public static function drain(): array
    {
        $lock = self::lock();
        $lock->block(3);

        try {
            $pending = self::pending();
            Cache::forget(self::PENDING_KEY);

            return $pending;
        } finally {
            $lock->release();
        }
    }

    public static function pendingCount(): int
    {
        return array_sum(array_map('count', self::pending()));
    }

    /**
     * @return list<string>
     */
    public static function backlog(): array
    {
        $backlog = Cache::get(self::BACKLOG_KEY);

        return is_array($backlog) ? array_values(array_filter($backlog, is_string(...))) : [];
    }

    /**
     * Keep the newest BACKLOG_CAP URLs (the oldest are dropped).
     *
     * @param  list<string>  $urls
     */
    public static function storeBacklog(array $urls): void
    {
        if ($urls === []) {
            Cache::forget(self::BACKLOG_KEY);

            return;
        }

        Cache::forever(self::BACKLOG_KEY, array_slice(array_values($urls), -self::BACKLOG_CAP));
    }

    /**
     * Forget everything (gate off: re-enabling re-seeds, no flood).
     */
    public static function forgetState(): void
    {
        foreach ([self::SNAPSHOT_KEY, self::PENDING_KEY, self::BACKLOG_KEY, self::INFLIGHT_KEY] as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Prepend a result to the log (status and counts only: no URLs, no key).
     */
    public static function log(int $urls, string $status, string|int $code): void
    {
        $log = self::entries();
        array_unshift($log, ['at' => now()->toIso8601String(), 'urls' => $urls, 'status' => $status, 'code' => (string) $code]);

        Cache::forever(self::LOG_KEY, array_slice($log, 0, self::LOG_LIMIT));
    }

    /**
     * @return list<array{at: string, urls: int, status: string, code: string}>
     */
    public static function entries(): array
    {
        $log = Cache::get(self::LOG_KEY);

        return is_array($log) ? array_values($log) : [];
    }

    /**
     * Public URLs (every locale) of the pending ids that are in the sitemap.
     *
     * @param  array<string, list<int>>  $pending
     * @param  array<string, true>  $current  sitemap locs as keys
     * @return list<string>
     */
    public static function changedUrls(array $pending, array $current): array
    {
        $locales = array_values(array_filter((array) config('app.supported_locales'), is_string(...)));
        $urls = [];

        CanonicalHost::using(function () use ($pending, $current, $locales, &$urls): void {
            $add = function (string $url) use ($current, &$urls): void {
                if (isset($current[$url])) {
                    $urls[$url] = true;
                }
            };

            foreach (Product::query()->whereIn('id', $pending['product'] ?? [])->get(['id', 'slug']) as $product) {
                foreach ($locales as $locale) {
                    $add(route('site.product', ['locale' => $locale, 'product' => $product->slug]));
                }
            }

            foreach (Category::query()->whereIn('id', $pending['category'] ?? [])->get(['id', 'slug']) as $category) {
                foreach ($locales as $locale) {
                    $add(route('site.catalog.category', ['locale' => $locale, 'category' => $category->slug]));
                }
            }

            foreach (Brand::query()->whereIn('id', $pending['brand'] ?? [])->get(['id', 'slug']) as $brand) {
                foreach ($locales as $locale) {
                    $add(route('site.brands.show', ['locale' => $locale, 'brand' => $brand->slug]));
                }
            }

            foreach (Page::query()->whereIn('id', $pending['page'] ?? [])->get(['id', 'slug']) as $page) {
                foreach ($locales as $locale) {
                    $add(route('site.page', ['locale' => $locale, 'page' => $page->slug]));
                }
            }

            foreach (NewsPost::query()->whereIn('id', $pending['news_post'] ?? [])->get(['id', 'slug', 'kind', 'locales']) as $post) {
                $kind = $post->kind;

                if (! $kind instanceof NewsKind) {
                    continue;
                }

                foreach (array_intersect($locales, (array) $post->locales) as $locale) {
                    $add($kind->url($post->slug, $locale));
                }
            }
        });

        return array_keys($urls);
    }

    /**
     * POST a batch (≤ MAX_URLS) to IndexNow. Host, keyLocation and the URLs
     * are all on APP_URL. The caller interprets the response and must never
     * log its body or the request.
     *
     * @param  list<string>  $urls
     */
    public static function send(array $urls): Response
    {
        $key = self::key() ?? throw new \RuntimeException('IndexNow key is not set.');
        $base = rtrim((string) config('app.url'), '/');

        return Http::timeout(15)
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->post(self::ENDPOINT, [
                'host' => (string) parse_url($base, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => $base.'/'.$key.'.txt',
                'urlList' => array_values($urls),
            ]);
    }

    protected static function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, 10);
    }

    protected static function typeOf(Model $model): ?string
    {
        return match (true) {
            $model instanceof Product => 'product',
            $model instanceof Category => 'category',
            $model instanceof Brand => 'brand',
            $model instanceof Page => 'page',
            $model instanceof NewsPost => 'news_post',
            default => null,
        };
    }
}
