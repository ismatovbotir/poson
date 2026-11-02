<?php

namespace App\Support\Site;

use App\Enums\NewsKind;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use App\Support\Catalog\CategoryTree;
use App\Support\Content\VideoText;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use XMLWriter;

/**
 * /sitemap.xml (plan 03 D16): the home page, the catalog index, visible
 * categories, the brands list and the listed brands (active, with a visible
 * product; decision 2026-10-24), active static pages (plan 07) and published products, each in every locale with
 * xhtml:link alternates (plus x-default) and lastmod. News (plan 07 7b): the
 * news list in the locales that have visible news, and every visible post
 * only in its translated locales (alternates and x-default among those).
 * Knowledge articles (decision 2026-10-24) the same way: the articles list
 * and every visible article.
 *
 * Every URL is built on APP_URL (CanonicalHost), never on the request host,
 * so the cached document can't carry a foreign Host header.
 *
 * Cached for config('site.sitemap_cache_minutes') (one week), never past the next
 * scheduled news post (NewsDirectory::ttl()); Product, Category, Brand, Page and
 * NewsPost flush it after their save or delete commits. Prices never appear here.
 *
 * Limit: one sitemap file holds at most 50 000 URLs (and 50 MB). With three
 * locales that is about 16 000 categories + products; beyond that switch to a
 * sitemap index (sitemap-products-1.xml, ...).
 */
class Sitemap
{
    public const CACHE_KEY = 'site.sitemap';

    /**
     * The URL list for the admin preview, stored right after (and with the
     * same lifetime as) the document under CACHE_KEY.
     */
    public const URLS_KEY = 'site.sitemap.urls';

    /**
     * Global build lock: two builds never run at once (public cold start and
     * the admin refresh share it).
     */
    public const BUILD_LOCK = 'site.sitemap.build';

    public const LOCK_SECONDS = 120;

    /**
     * URL types counted in the admin status line.
     *
     * @var list<string>
     */
    public const TYPES = ['products', 'categories', 'brands', 'pages', 'news', 'articles', 'other'];

    /**
     * @var list<array{loc: string, lastmod: ?string, locale: string, type: string}>
     */
    private array $entries = [];

    /**
     * Visible videos (plan 19) by owner ("morph:id"), loaded once per build.
     *
     * @var array<string, list<Video>>
     */
    private array $videos = [];

    /**
     * Last good document (no TTL, never flushed): served while a rebuild is
     * running or after a flush, so a cold cache never makes a request wait.
     */
    public const LAST_KEY = 'site.sitemap.last';

    /**
     * Bumped by every flush(); a build that started before a flush is not
     * stored (it may carry the old catalog state).
     */
    public const VERSION_KEY = 'site.sitemap.version';

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::URLS_KEY);
    }

    /**
     * The cached document. A cold cache is built by the request that gets the
     * global lock; the others get the last good copy at once, or a 503 with
     * Retry-After when there is none yet. Nobody waits.
     */
    public function xml(): string
    {
        if ($payload = $this->payload()) {
            return $payload['xml'];
        }

        $lock = Cache::lock(self::BUILD_LOCK, self::LOCK_SECONDS);

        if ($lock->get()) {
            try {
                return $this->payload()['xml'] ?? $this->store($this->generate())['xml'];
            } finally {
                $lock->release();
            }
        }

        $last = Cache::get(self::LAST_KEY);

        if (is_array($last) && is_string($last['xml'] ?? null)) {
            return $last['xml'];
        }

        throw new ServiceUnavailableHttpException(5);
    }

    /**
     * The stored document with its meta, or null when nothing (valid) is cached.
     *
     * @return array{xml: string, generated_at: string, expires_at: string, counts: array<string, int>}|null
     */
    public function payload(): ?array
    {
        $payload = Cache::get(self::CACHE_KEY);

        return is_array($payload) && is_string($payload['xml'] ?? null) && is_string($payload['generated_at'] ?? null) && is_string($payload['expires_at'] ?? null) && is_array($payload['counts'] ?? null)
            ? $payload
            : null;
    }

    /**
     * The URLs of the stored document (preview), or null when not generated.
     *
     * @return list<array{loc: string, lastmod: ?string, locale: string, type: string}>|null
     */
    public function urls(): ?array
    {
        if ($this->payload() === null) {
            return null;
        }

        $urls = Cache::get(self::URLS_KEY);

        return is_array($urls) ? $urls : null;
    }

    /**
     * Build the document on APP_URL (never the request host) and collect its
     * URL list and counts per type. Doesn't touch the cache.
     *
     * @return array{xml: string, urls: list<array{loc: string, lastmod: ?string, locale: string, type: string}>, counts: array<string, int>, version: string}
     */
    public function generate(): array
    {
        $version = (string) Cache::get(self::VERSION_KEY, '0');

        return CanonicalHost::using(function () use ($version): array {
            $xml = $this->build();
            $counts = array_fill_keys(self::TYPES, 0);

            foreach ($this->entries as $entry) {
                $counts[$entry['type']]++;
            }

            return ['xml' => $xml, 'urls' => $this->entries, 'counts' => $counts, 'version' => $version];
        });
    }

    /**
     * Put a generated document into the cache: the URL list first, then the
     * document with its meta (one value), both with the same lifetime, then the
     * last-good copy. Nothing is written when a flush happened since generate()
     * started (the document may be stale); the payload is still returned so the
     * caller can serve it once.
     *
     * @param  array{xml: string, urls: list<array{loc: string, lastmod: ?string, locale: string, type: string}>, counts: array<string, int>, version?: string}  $generated
     * @return array{xml: string, generated_at: string, expires_at: string, counts: array<string, int>}
     */
    public function store(array $generated): array
    {
        $expiresAt = NewsDirectory::ttl((int) config('site.sitemap_cache_minutes', 10080) * 60);
        $payload = [
            'xml' => $generated['xml'],
            'generated_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'counts' => $generated['counts'],
        ];

        if (($generated['version'] ?? (string) Cache::get(self::VERSION_KEY, '0')) === (string) Cache::get(self::VERSION_KEY, '0')) {
            Cache::put(self::URLS_KEY, $generated['urls'], $expiresAt);
            Cache::put(self::CACHE_KEY, $payload, $expiresAt);
            Cache::forever(self::LAST_KEY, $payload);
        }

        return $payload;
    }

    public function build(): string
    {
        $this->entries = [];

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $xml->writeAttribute('xmlns:video', 'http://www.google.com/schemas/sitemap-video/1.1');

        $this->loadVideos();

        $latestProduct = Product::query()->visible()->max('updated_at');

        $this->write($xml, fn (string $locale): string => route('site.home', ['locale' => $locale]));
        $this->write($xml, fn (string $locale): string => route('site.catalog.index', ['locale' => $locale]), $this->date($latestProduct));

        $categories = Category::query()
            ->whereIn('id', CategoryTree::visibleIds())
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'slug', 'updated_at']);

        foreach ($categories as $category) {
            $this->write(
                $xml,
                fn (string $locale): string => route('site.catalog.category', ['locale' => $locale, 'category' => $category->slug]),
                $category->updated_at,
                type: 'categories',
                videos: $this->videosOf('category', $category->id),
            );
        }

        $this->brands($xml);

        // Active static pages (plan 07), legal ones included.
        foreach (PageDirectory::active() as $page) {
            $this->write(
                $xml,
                fn (string $locale): string => route('site.page', ['locale' => $locale, 'page' => $page['slug']]),
                $this->date($page['modified_at'] ?? null),
                type: 'pages',
            );
        }

        foreach (NewsKind::cases() as $kind) {
            $this->news($xml, $kind);
        }

        Product::query()
            ->visible()
            ->select(['id', 'slug', 'updated_at'])
            ->chunkById(500, function ($products) use ($xml): void {
                foreach ($products as $product) {
                    $this->write(
                        $xml,
                        fn (string $locale): string => route('site.product', ['locale' => $locale, 'product' => $product->slug]),
                        $product->updated_at,
                        type: 'products',
                        videos: $this->videosOf('product', $product->id),
                    );
                }
            });

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * The brands list and every listed brand; a brand's lastmod is the later
     * of its own change and its newest visible product's (the list: the
     * latest of those).
     */
    private function brands(XMLWriter $xml): void
    {
        $brands = Brand::query()
            ->listed()
            ->withMax(['products as visible_products_updated_at' => fn (Builder $products) => $products->visible()], 'updated_at')
            ->ordered()
            ->get(['id', 'slug', 'updated_at']);

        if ($brands->isEmpty()) {
            return;
        }

        $modified = $brands->mapWithKeys(fn (Brand $brand): array => [$brand->id => collect([
            $brand->updated_at,
            $this->date($brand->getAttribute('visible_products_updated_at')),
        ])->filter()->max()]);

        $this->write($xml, fn (string $locale): string => route('site.brands.index', ['locale' => $locale]), $modified->filter()->max());

        foreach ($brands as $brand) {
            $this->write(
                $xml,
                fn (string $locale): string => route('site.brands.show', ['locale' => $locale, 'brand' => $brand->slug]),
                $modified[$brand->id],
                type: 'brands',
                videos: $this->videosOf('brand', $brand->id),
            );
        }
    }

    /**
     * The list of the kind (locales with visible posts of the kind, lastmod =
     * the newest post in that locale) and every visible post of the kind in
     * its translated locales only.
     */
    private function news(XMLWriter $xml, NewsKind $kind): void
    {
        $listLocales = array_values(array_filter(config('app.supported_locales'), fn (string $locale): bool => NewsDirectory::hasVisible($locale, $kind)));

        if ($listLocales === []) {
            return;
        }

        $newest = [];

        foreach ($listLocales as $locale) {
            $newest[$locale] = $this->date(NewsPost::query()->ofKind($kind)->visibleIn($locale)->max('published_at'));
        }

        $this->write(
            $xml,
            fn (string $locale): string => $kind->listUrl($locale),
            fn (string $locale): ?CarbonInterface => $newest[$locale] ?? null,
            $listLocales,
        );

        NewsPost::query()
            ->ofKind($kind)
            ->visible()
            ->select(['id', 'slug', 'locales', 'published_at', 'content_updated_at', 'updated_at'])
            ->chunkById(500, function ($posts) use ($xml, $kind): void {
                foreach ($posts as $post) {
                    $locales = array_values(array_intersect(config('app.supported_locales'), (array) $post->locales));

                    if ($locales !== []) {
                        $this->write(
                            $xml,
                            fn (string $locale): string => $kind->url($post->slug, $locale),
                            $post->contentUpdatedAt(),
                            $locales,
                            $kind === NewsKind::Article ? 'articles' : 'news',
                            $this->videosOf('news_post', $post->id),
                        );
                    }
                }
            });
    }

    /**
     * One <url> per locale, each listing every locale as an alternate
     * (default: all supported locales; x-default is the default locale when
     * listed, else the first one).
     *
     * @param  callable(string): string  $url
     * @param  CarbonInterface|(callable(string): ?CarbonInterface)|null  $lastModified  one date, or one per locale
     * @param  list<string>|null  $locales
     * @param  string  $type  one of TYPES, for the admin counts
     * @param  list<Video>  $videos  the owner's visible videos (plan 19), written in every locale's <url>
     */
    private function write(XMLWriter $xml, callable $url, CarbonInterface|callable|null $lastModified = null, ?array $locales = null, string $type = 'other', array $videos = []): void
    {
        $locales ??= config('app.supported_locales');
        $urls = [];

        foreach ($locales as $locale) {
            $urls[$locale] = $url($locale);
        }

        $default = $urls[config('app.default_locale', 'uz')] ?? reset($urls);

        foreach ($urls as $locale => $loc) {
            $xml->startElement('url');
            $xml->writeElement('loc', $loc);
            $modified = is_callable($lastModified) ? $lastModified($locale) : $lastModified;

            if ($modified !== null) {
                $xml->writeElement('lastmod', $modified->toAtomString());
            }

            $this->entries[] = ['loc' => $loc, 'lastmod' => $modified?->toAtomString(), 'locale' => (string) $locale, 'type' => $type];

            foreach ([...$urls, 'x-default' => $default] as $hreflang => $href) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', (string) $hreflang);
                $xml->writeAttribute('href', $href);
                $xml->endElement();
            }

            foreach ($videos as $video) {
                $this->writeVideo($xml, $video, (string) $locale);
            }

            $xml->endElement();
        }
    }

    /**
     * Load the visible videos of all owners with one query.
     */
    private function loadVideos(): void
    {
        $this->videos = [];

        Video::query()->visible()->ordered()->get()->each(function (Video $video): void {
            $this->videos[$video->videoable_type.':'.$video->videoable_id][] = $video;
        });
    }

    /**
     * @return list<Video>
     */
    private function videosOf(string $morph, int $id): array
    {
        return $this->videos[$morph.':'.$id] ?? [];
    }

    /**
     * One <video:video>. A video without a cached local thumbnail is skipped
     * (thumbnail_loc is required and must be ours). Title and description are
     * cleaned again here (valid UTF-8, no control characters) and capped at the
     * protocol's 100 / 2048 characters; XMLWriter escapes markup.
     */
    private function writeVideo(XMLWriter $xml, Video $video, string $locale): void
    {
        $thumbnail = $video->thumbnailUrl();

        if ($thumbnail === null) {
            return;
        }

        $title = VideoText::clean($video->displayTitle($locale) ?? __('site.videos.fallback_title', [], $locale), 100)
            ?? __('site.videos.fallback_title', [], $locale);

        $xml->startElement('video:video');
        $xml->writeElement('video:thumbnail_loc', url($thumbnail));
        $xml->writeElement('video:title', $title);
        $xml->writeElement('video:description', $title);
        $xml->writeElement('video:player_loc', $video->seoEmbedUrl());

        if ($video->published_on !== null) {
            $xml->writeElement('video:publication_date', $video->published_on->format('Y-m-d'));
        }

        $xml->endElement();
    }

    private function date(mixed $value): ?CarbonInterface
    {
        return $value === null ? null : ($value instanceof CarbonInterface ? $value : now()->parse((string) $value));
    }
}
