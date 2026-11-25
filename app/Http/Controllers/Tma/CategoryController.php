<?php

namespace App\Http\Controllers\Tma;

use App\Models\Category;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A Mini App category (plan 20 §3, M2): the same listing as the site
 * (CatalogQuery: filters, match modes where the attribute allows the
 * switch, caps, sort), server-rendered, PER_PAGE products per page.
 *
 * The filter sheet with its facet counts is its own screen (filters(), a
 * GET form back to the listing), so the listing stays within the §8 query
 * budget. A hidden category or a page past the end is a 404.
 */
class CategoryController extends TmaController
{
    public const PER_PAGE = 20;

    public function __invoke(Request $request, Category $category): Response
    {
        abort_unless(CategoryTree::isVisible($category->id), 404);

        $query = $this->query($request, $category);
        $products = $query->paginate(self::PER_PAGE)
            ->withPath(route('tma.category', ['category' => $category->slug]))
            ->appends($query->queryParameters());

        abort_if($products->currentPage() > max(1, $products->lastPage()), 404);

        return $this->page($request, 'tma.category', [
            'category' => $category,
            'title' => (string) $category->name,
            'back' => ($parentId = $category->parent_id) !== null && CategoryTree::isVisible($parentId)
                ? route('tma.category', ['category' => CategoryTree::find($parentId)['slug']])
                : route('tma.home'),
            'subcategories' => array_map(fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('tma.category', ['category' => $node['slug']]),
            ], CategoryTree::visibleChildren($category->id)),
            'products' => $products,
            'cards' => ProductCards::fromProducts($products->items()),
            'activeCount' => $query->activeFilterCount(),
            'filtersUrl' => route('tma.category.filters', ['category' => $category->slug, ...$query->queryParameters()]),
        ]);
    }

    /**
     * The filter sheet: facets of the current selection (brand, stock, Retail
     * price, attributes with colour swatches as SVG fill), sort; submits to the listing.
     */
    public function filters(Request $request, Category $category): Response
    {
        abort_unless(CategoryTree::isVisible($category->id), 404);

        $query = $this->query($request, $category);

        return $this->page($request, 'tma.filters', [
            'category' => $category,
            'title' => __('tma.filters.title'),
            'back' => route('tma.category', ['category' => $category->slug, ...$query->queryParameters()]),
            'facets' => $query->facets(),
            'sort' => $query->sort,
            'sorts' => CatalogQuery::SORTS,
            'priceMin' => $query->priceMin,
            'priceMax' => $query->priceMax,
        ]);
    }

    protected function query(Request $request, Category $category): CatalogQuery
    {
        return new CatalogQuery(
            category: $category,
            brand: $request->query('brand', []),
            stock: $request->query('stock', []),
            priceMin: $request->query('price_min'),
            priceMax: $request->query('price_max'),
            attributeFilters: $request->query('f', []),
            sort: $request->query('sort', CatalogQuery::DEFAULT_SORT),
            matchModes: $request->query('m', []),
        );
    }
}
