<?php

namespace App\Support\Site;

use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * /sitemap.xml (plan 03 D16): the home page, the catalog index, visible
 * categories and published products, each in every locale with
 * xhtml:link alternates (plus x-default) and lastmod.
 *
 * Every URL is built on APP_URL (CanonicalHost), never on the request host,
 * so the cached document can't carry a foreign Host header.
 *
 * Cached for config('site.sitemap_cache_minutes'); Product and Category flush
 * it after their save or delete commits. Prices never appear here.
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
            now()->addMinutes((int) config('site.sitemap_cache_minutes', 60)),
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
     * One <url> per locale, each listing every locale as an alternate.
     *
     * @param  callable(string): string  $url
     */
    private function write(XMLWriter $xml, callable $url, ?CarbonInterface $lastModified = null): void
    {
        $locales = config('app.supported_locales');
        $urls = [];

        foreach ($locales as $locale) {
            $urls[$locale] = $url($locale);
        }

        $default = $urls[config('app.default_locale', 'uz')] ?? reset($urls);

        foreach ($urls as $loc) {
            $xml->startElement('url');
            $xml->writeElement('loc', $loc);

            if ($lastModified !== null) {
                $xml->writeElement('lastmod', $lastModified->toAtomString());
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
