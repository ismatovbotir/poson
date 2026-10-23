<?php

namespace App\Support\Site;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Active static pages (plan 07 K4), cached: the site menus link a page only
 * while it is active (Navigation::page()), the sitemap and llms.txt list
 * them. One query on a cache miss; a per-request copy in the container.
 *
 * Page saves and deletes flush it (and, after the commit, the sitemap and
 * llms.txt through flushAll()). Mass updates bypass model events: call
 * PageDirectory::flushAll() after them (PagesSeeder does). modified_at is
 * Page::contentUpdatedAt() (sitemap lastmod, like the JSON-LD dateModified).
 *
 * @phpstan-type PageEntry array{id: int, slug: string, template: string, title: array<string, string>, is_system: bool, modified_at: string|null}
 */
class PageDirectory
{
    public const CACHE_KEY = 'site.pages';

    private const MEMO = 'site.pages.memo';

    /**
     * Active pages keyed by slug, in admin order.
     *
     * @return array<string, PageEntry>
     */
    public static function active(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $pages = Cache::rememberForever(self::CACHE_KEY, fn (): array => Page::query()
            ->active()
            ->ordered()
            ->get(['id', 'slug', 'template', 'title', 'is_system', 'content_updated_at', 'updated_at', 'created_at'])
            ->mapWithKeys(fn (Page $page): array => [$page->slug => [
                'id' => $page->id,
                'slug' => $page->slug,
                'template' => $page->template->value,
                'title' => $page->getTranslations('title'),
                'is_system' => $page->is_system,
                'modified_at' => $page->contentUpdatedAt()?->toAtomString(),
            ]])
            ->all());

        app()->instance(self::MEMO, $pages);

        return $pages;
    }

    public static function isActive(string $slug): bool
    {
        return array_key_exists($slug, static::active());
    }

    /**
     * @return PageEntry|null
     */
    public static function find(string $slug): ?array
    {
        return static::active()[$slug] ?? null;
    }

    /**
     * Title in the locale, falling back to the default locale.
     */
    public static function title(string $slug, ?string $locale = null): string
    {
        $titles = static::find($slug)['title'] ?? [];

        return (string) ($titles[$locale ?? app()->getLocale()] ?? $titles[config('app.default_locale', 'uz')] ?? '');
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
