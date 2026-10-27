<?php

namespace App\Support\Site;

use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Models\NewsPost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the site has news, and articles (NewsKind, decision 2026-10-24),
 * per locale (plan 07 7b), cached: the menus link the news and articles
 * lists, and the home page shows its "Latest news" section, only while at
 * least one post of that kind is visible in the locale.
 *
 * Scheduled posts (no cron) need no flush: the cache keeps, per locale, the
 * earliest publication date of the published posts translated in it, and
 * the comparison with now() happens on every read. It also keeps the future
 * publication dates, so the sitemap and llms.txt caches never outlive the
 * next scheduled post (ttl()).
 *
 * One small query (kind, date and locales of the published posts) on a cache
 * miss; a per-request copy in the container. NewsPost saves and deletes
 * flush it (and, after the commit, the sitemap and llms.txt through
 * flushAll()).
 *
 * @phpstan-type Snapshot array{first: array<string, array<string, string|null>>, scheduled: list<string>}
 */
class NewsDirectory
{
    public const CACHE_KEY = 'site.news.v2';

    private const MEMO = 'site.news.memo';

    /**
     * At least one post of the kind (default: news) is visible in the locale
     * (default: the current one).
     */
    public static function hasVisible(?string $locale = null, NewsKind $kind = NewsKind::News): bool
    {
        $first = static::snapshot()['first'][$kind->value][$locale ?? app()->getLocale()] ?? null;

        return $first !== null && Carbon::parse($first)->lessThanOrEqualTo(now());
    }

    /**
     * The next publication date still in the future, if any.
     */
    public static function nextScheduledAt(): ?CarbonInterface
    {
        foreach (static::snapshot()['scheduled'] as $date) {
            $at = Carbon::parse($date);

            if ($at->greaterThan(now())) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Cache lifetime for documents that list news (sitemap, llms.txt):
     * the given seconds, cut short at the next scheduled post.
     */
    public static function ttl(int $seconds): CarbonInterface
    {
        $until = now()->addSeconds($seconds);
        $next = static::nextScheduledAt();

        return $next !== null && $next->lessThan($until) ? $next->copy()->addSecond() : $until;
    }

    /**
     * @return Snapshot
     */
    protected static function snapshot(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $snapshot = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $first = [];
            $scheduled = [];

            foreach (NewsKind::cases() as $kind) {
                $first[$kind->value] = array_fill_keys(config('app.supported_locales'), null);
            }

            $posts = NewsPost::query()
                ->where('status', NewsStatus::Published->value)
                ->whereNotNull('published_at')
                ->orderBy('published_at')
                ->toBase()
                ->get(['kind', 'published_at', 'locales']);

            foreach ($posts as $post) {
                $at = Carbon::parse($post->published_at);
                $kind = (string) $post->kind;

                foreach ((array) json_decode((string) $post->locales, true) as $locale) {
                    if (isset($first[$kind]) && array_key_exists($locale, $first[$kind]) && $first[$kind][$locale] === null) {
                        $first[$kind][$locale] = $at->toAtomString();
                    }
                }

                if ($at->greaterThan(now()) && count($scheduled) < 100) {
                    $scheduled[] = $at->toAtomString();
                }
            }

            return ['first' => $first, 'scheduled' => array_values(array_unique($scheduled))];
        });

        app()->instance(self::MEMO, $snapshot);

        return $snapshot;
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The directory plus the documents built from it (sitemap.xml, llms.txt).
     */
    public static function flushAll(): void
    {
        static::flush();
        Sitemap::flush();
        LlmsTxt::flush();
    }
}
