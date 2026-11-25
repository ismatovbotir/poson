<?php

namespace App\Actions\Site;

use App\Actions\Chat\SearchProducts;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Search as SearchText;
use Illuminate\Support\Facades\Cache;

/**
 * Header search suggestions (plan 43 #40, UQ2-1 … UQ2-7) for
 * GET /{locale}/search/suggest?q=.
 *
 * - The term is SearchProducts::term(); under MIN_LENGTH characters after
 *   normalisation (spaces not counted) nothing is queried.
 * - Products: SearchProducts::query() (published only) limited to
 *   MAX_PRODUCTS, selecting only the card columns.
 * - Categories: matched in PHP against the visible category names of the
 *   current locale from the cached CategoryTree (no SQL LIKE), MAX_CATEGORIES.
 * - The payload is an explicit field map (name, brand, category, url, image);
 *   never a serialized model, never a price level, stock or cost field.
 * - Cached per locale and term hash (suggest:{locale}:sha1) for CACHE_SECONDS.
 *   The term itself is never logged or used as a cache key in clear.
 */
class SuggestSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_PRODUCTS = 5;

    public const MAX_CATEGORIES = 5;

    public const CACHE_SECONDS = 120;

    /**
     * The product columns a suggestion needs (media and brand are eager-loaded by id).
     */
    private const COLUMNS = ['products.id', 'products.category_id', 'products.brand_id', 'products.name', 'products.slug'];

    public function __construct(private SearchProducts $search) {}

    /**
     * @return array{products: list<array{name: string, brand: ?string, category: string, url: string, image: ?string}>, categories: list<array{name: string, url: string}>}
     */
    public function handle(mixed $raw): array
    {
        $term = SearchProducts::term($raw);
        $normalized = SearchText::normalize($term);

        if (mb_strlen(str_replace(' ', '', $normalized)) < self::MIN_LENGTH) {
            return ['products' => [], 'categories' => []];
        }

        $locale = app()->getLocale();

        return Cache::remember(
            'suggest:'.$locale.':'.sha1($normalized),
            self::CACHE_SECONDS,
            fn (): array => ['products' => $this->products($term), 'categories' => $this->categories($normalized, $locale)],
        );
    }

    /**
     * @return list<array{name: string, brand: ?string, category: string, url: string, image: ?string}>
     */
    private function products(string $term): array
    {
        $query = $this->search->query($term);

        if ($query === null) {
            return [];
        }

        return $query
            ->select(self::COLUMNS)
            ->with(['brand:id,name,is_active', 'media'])
            ->limit(self::MAX_PRODUCTS)
            ->get()
            ->map(fn (Product $product): array => [
                'name' => (string) $product->name,
                'brand' => $product->publicBrand()?->name,
                'category' => CategoryTree::name($product->category_id),
                'url' => route('site.product', ['product' => $product->slug]),
                'image' => $product->imageUrl('thumb'),
            ])
            ->values()
            ->all();
    }

    /**
     * Visible categories whose name in the locale contains every word of the term.
     *
     * @return list<array{name: string, url: string}>
     */
    private function categories(string $normalized, string $locale): array
    {
        $words = array_values(array_filter(explode(' ', $normalized), fn (string $word): bool => $word !== ''));
        $matches = [];

        foreach (CategoryTree::visibleIds() as $id) {
            $name = CategoryTree::name($id, $locale);
            $haystack = SearchText::normalize($name);

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    continue 2;
                }
            }

            $matches[] = [
                'name' => $name,
                'url' => route('site.catalog.category', ['category' => CategoryTree::find($id)['slug'] ?? '']),
            ];

            if (count($matches) >= self::MAX_CATEGORIES) {
                break;
            }
        }

        return $matches;
    }
}
