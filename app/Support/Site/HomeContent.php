<?php

namespace App\Support\Site;

use App\Data\Site\BrandItem;
use App\Data\Site\CategoryTile;
use App\Data\Site\ProductCard;
use App\Data\Site\ServiceItem;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;

/**
 * Content blocks of the home page, from the catalog: top-level categories
 * with their product counts, featured products and active brands. The home
 * page hides a section while its list is empty.
 */
class HomeContent
{
    /**
     * Most featured products on the home page.
     */
    public const POPULAR_LIMIT = 8;

    /**
     * Icon of a category tile when the category has none.
     */
    public const DEFAULT_ICON = 'grid';

    /**
     * @var list<string>
     */
    protected const SERVICES = ['warehouse', 'marking', 'repair'];

    /**
     * Visible top-level categories in sort order, each with the number of
     * published products in it and its visible subcategories.
     *
     * @return list<CategoryTile>
     */
    public function categories(): array
    {
        $roots = CategoryTree::visibleChildren(null);

        if ($roots === []) {
            return [];
        }

        $counts = Product::query()
            ->visible()
            ->groupBy('products.category_id')
            ->selectRaw('products.category_id as category_id, count(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'category_id');

        return array_map(function (array $node) use ($counts): CategoryTile {
            $count = 0;

            foreach (CategoryTree::visibleSubtreeIds($node['id']) as $id) {
                $count += (int) ($counts[$id] ?? 0);
            }

            return new CategoryTile(
                name: CategoryTree::name($node['id']),
                url: route('site.catalog.category', ['locale' => app()->getLocale(), 'category' => $node['slug']]),
                icon: $node['icon'] ?? self::DEFAULT_ICON,
                productsCount: $count,
            );
        }, $roots);
    }

    /**
     * Featured published products (sort order), at most POPULAR_LIMIT.
     *
     * @return list<ProductCard>
     */
    public function popularProducts(): array
    {
        $products = Product::query()
            ->visible()
            ->featured()
            ->with(ProductCards::EAGER_LOADS)
            ->orderBy('products.sort')
            ->orderBy('products.id')
            ->limit(self::POPULAR_LIMIT)
            ->get();

        return ProductCards::fromProducts($products);
    }

    /**
     * The company's services (always shown; copy lives in lang/site.php).
     *
     * @return list<ServiceItem>
     */
    public function services(): array
    {
        return array_map(
            fn (string $key): ServiceItem => new ServiceItem(
                key: $key,
                title: __("site.home.services.items.{$key}.title"),
                text: __("site.home.services.items.{$key}.text"),
                url: Navigation::service($key),
            ),
            self::SERVICES,
        );
    }

    /**
     * Active brands in sort order with their logo conversion (else the name
     * is shown); each links to the catalog filtered by the brand.
     *
     * @return list<BrandItem>
     */
    public function brands(): array
    {
        return Brand::query()
            ->active()
            ->with('media')
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand): BrandItem => new BrandItem(
                name: $brand->name,
                slug: $brand->slug,
                url: route('site.catalog.index', ['locale' => app()->getLocale(), 'brand' => [$brand->slug]]),
                logo: $brand->imageUrl('thumb'),
            ))
            ->all();
    }
}
