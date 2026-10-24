<?php

namespace App\Support\Site;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * /sitemap.xml (plan 03 D16): the home page, the catalog index, visible
 * categories, the brands list and the listed brands (active, with a visible
 * product; decision 2026-10-24), active static pages (plan 07) and published products, each in every locale with
 * xhtml:link alternates (plus x-default) and lastmod. News (plan 07 7b): the
 * news list in the locales that have visible news, and every visible post
 * only in its translated locales (alternates and x-default among those).
 *
 * Every URL is built on APP_URL (CanonicalHost), never on the request host,
 * so the cached document can't carry a foreign Host header.
 *
 * Cached for config('site.sitemap_cache_minutes'), never past the next
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

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function xml(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            NewsDirectory::ttl((int) config('site.sitemap_cache_minutes', 60) * 60),
            fn (): string => CanonicalHost::using(fn (): string => $this->build()),
        );
    }

    public function build(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

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
            );
        }

        $this->brands($xml);

        // Active static pages (plan 07), legal ones included.
        foreach (PageDirectory::active() as $page) {
            $this->write(
                $xml,
                fn (string $locale): string => route('site.page', ['locale' => $locale, 'page' => $page['slug']]),
                $this->date($page['modified_at'] ?? null),
            );
        }

        $this->news($xml);

        Product::query()
            ->visible()
            ->select(['id', 'slug', 'updated_at'])
            ->chunkById(500, function ($products) use ($xml): void {
                foreach ($products as $product) {
                    $this->write(
                        $xml,
                        fn (string $locale): string => route('site.product', ['locale' => $locale, 'product' => $product->slug]),
                        $product->updated_at,
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
            );
        }
    }

    /**
     * The news list (locales with visible news, lastmod = the newest post in that locale)
     * and every visible post in its translated locales only.
     */
    private function news(XMLWriter $xml): void
    {
        $listLocales = array_values(array_filter(config('app.supported_locales'), fn (string $locale): bool => NewsDirectory::hasVisible($locale)));

        if ($listLocales === []) {
            return;
        }

        $newest = [];

        foreach ($listLocales as $locale) {
            $newest[$locale] = $this->date(NewsPost::query()->visibleIn($locale)->max('published_at'));
        }

        $this->write(
            $xml,
            fn (string $locale): string => route('site.news.index', ['locale' => $locale]),
            fn (string $locale): ?CarbonInterface => $newest[$locale] ?? null,
            $listLocales,
        );

        NewsPost::query()
            ->visible()
            ->select(['id', 'slug', 'locales', 'published_at', 'content_updated_at', 'updated_at'])
            ->chunkById(500, function ($posts) use ($xml): void {
                foreach ($posts as $post) {
                    $locales = array_values(array_intersect(config('app.supported_locales'), (array) $post->locales));

                    if ($locales !== []) {
                        $this->write(
                            $xml,
                            fn (string $locale): string => route('site.news.show', ['locale' => $locale, 'newsPost' => $post->slug]),
                            $post->contentUpdatedAt(),
                            $locales,
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
     */
    private function write(XMLWriter $xml, callable $url, CarbonInterface|callable|null $lastModified = null, ?array $locales = null): void
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

            foreach ([...$urls, 'x-default' => $default] as $hreflang => $href) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', (string) $hreflang);
                $xml->writeAttribute('href', $href);
                $xml->endElement();
            }

            $xml->endElement();
        }
    }

    private function date(mixed $value): ?CarbonInterface
    {
        return $value === null ? null : ($value instanceof CarbonInterface ? $value : now()->parse((string) $value));
    }
}
