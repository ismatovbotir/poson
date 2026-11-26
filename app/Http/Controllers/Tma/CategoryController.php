<?php

namespace App\Http\Controllers\Tma;

use App\Models\Category;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryFacetCache;
use App\Support\Catalog\CategoryTree;
use App\Support\Telegram\MiniApp\TmaCards;
use App\Support\Telegram\MiniApp\TmaCartSummary;
use App\Support\Telegram\MiniApp\TmaIcons;
use App\Support\Telegram\MiniApp\TmaReturn;
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
 *
 * Plan 45: the listing adds the category icon, the visible parents as a
 * breadcrumb row, a toolbar (Filters with the count, Sort, removable chips
 * of the active filters), cards with their cart action (TmaCards) and the
 * cart bar; within 15 queries with the cart priced (MA-56).
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

        $cart = $this->cartSummary($request);
        $node = fn (array $node): array => [
            'label' => CategoryTree::name($node['id']),
            'url' => route('tma.category', ['category' => $node['slug']]),
            'icon' => TmaIcons::name($node['icon'] ?? null),
        ];

        return $this->page($request, 'tma.category', [
            'category' => $category,
            'title' => (string) $category->name,
            'icon' => TmaCards::icon($category->id),
            'back' => ($parentId = $category->parent_id) !== null && CategoryTree::isVisible($parentId)
                ? route('tma.category', ['category' => CategoryTree::find($parentId)['slug']])
                : route('tma.home'),
            'breadcrumbs' => array_map(
                fn (int $id): array => $node(CategoryTree::find($id)),
                array_reverse(array_values(array_filter(CategoryTree::ancestorIds($category->id), fn (int $id): bool => CategoryTree::isVisible($id)))),
            ),
            'subcategories' => array_map($node, CategoryTree::visibleChildren($category->id)),
            'products' => $products,
            'cards' => TmaCards::fromProducts($products->items(), $cart ?? new TmaCartSummary),
            'from' => TmaReturn::category($category->id, $products->currentPage()),
            'activeCount' => $query->activeFilterCount(),
            'chips' => $query->activeFilterCount() > 0 ? $this->chips($query, $category) : [],
            'sortLabel' => __('site.catalog.sort.'.$query->sort),
            'filtersUrl' => route('tma.category.filters', ['category' => $category->slug, ...$query->queryParameters()]),
            'cartBar' => $cart,
        ]);
    }

    /**
     * Removable chips of the active filters (plan 45 §2): each one a plain GET
     * link to the listing without that filter, built from the normalized
     * query parameters (never from the raw request).
     *
     * @return list<array{label: string, url: string}>
     */
    protected function chips(CatalogQuery $query, Category $category): array
    {
        $parameters = $query->queryParameters();
        $chips = [];

        foreach ($query->activeFilters() as $chip) {
            [$type, $value, $option] = $chip['remove'];
            $without = $parameters;

            match ($type) {
                'brand', 'stock' => $without[$type] = array_values(array_filter((array) ($without[$type] ?? []), fn (mixed $kept): bool => (string) $kept !== $value)),
                'price_min', 'price_max' => $without[$type] = null,
                'option' => $without['f'][$value] = array_values(array_filter((array) ($without['f'][$value] ?? []), fn (mixed $kept): bool => (string) $kept !== $option)),
                default => $without['f'][$value] = [],
            };

            if (in_array($type, ['option', 'attribute'], true) && ($without['f'][$value] ?? []) === []) {
                unset($without['f'][$value], $without['m'][$value]);
            }

            $chips[] = [
                'label' => $chip['label'],
                'url' => route('tma.category', ['category' => $category->slug, ...array_filter($without, fn (mixed $kept): bool => $kept !== null && $kept !== [])]),
            ];
        }

        return $chips;
    }

    /**
     * The filter sheet: facets of the current selection (brand, stock, Retail
     * price, attributes with colour swatches as SVG fill), sort; submits to the listing.
     *
     * Plan 45 B / MA-50: without any active filter and without an m[] match
     * mode, the facets come from CategoryFacetCache (after the live
     * isVisible check, in the app locale set by tma.locale); a filtered
     * sheet is always built live.
     */
    public function filters(Request $request, Category $category): Response
    {
        abort_unless(CategoryTree::isVisible($category->id), 404);

        $query = $this->query($request, $category);
        $facets = $query->activeFilterCount() === 0 && ! $request->query->has('m')
            ? CategoryFacetCache::remember($category, app()->getLocale(), fn (): array => $query->facets())
            : $query->facets();

        return $this->page($request, 'tma.filters', [
            'category' => $category,
            'title' => __('tma.filters.title'),
            'back' => route('tma.category', ['category' => $category->slug, ...$query->queryParameters()]),
            'facets' => $facets,
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
