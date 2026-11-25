<?php

namespace App\Livewire\Site;

use App\Actions\Chat\SearchProducts;
use App\Data\Site\CategoryTile;
use App\Models\Product;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;
use App\Support\CompanySettings;
use App\Support\Site\HomeContent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/**
 * Search results (/{locale}/search?q=): published products whose
 * search_text (SKU, model, names in every locale, brand) contains every
 * word of the query, normalized like the admin search (lower case, Uzbek
 * apostrophes ʻ ʼ ' removed), so Cyrillic and apostrophe variants match.
 * The query itself is SearchProducts, shared with the chat bot (plan 28 C3).
 * Never indexed (noindex,follow; search URLs are disallowed in robots.txt).
 *
 * Plan 43 #39 (UQ2-9): category chips with counts (one grouped query, at
 * most MAX_CHIPS) and the catalog sort. ?category= is an int checked against
 * the visible categories of the current results, else ignored; ?sort= is one
 * of CatalogQuery::SORTS, else the default. The pager keeps only those
 * normalised values. #42: the empty result shows the phone, Telegram and
 * up to POPULAR_CATEGORIES visible categories.
 */
class Search extends Component
{
    use WithPagination;

    public const MAX_LENGTH = SearchProducts::MAX_LENGTH;

    /**
     * Words of the query that must all match.
     */
    public const MAX_WORDS = SearchProducts::MAX_WORDS;

    public const MAX_CHIPS = 12;

    public const POPULAR_CATEGORIES = 4;

    /**
     * Untyped: a wrong type from the URL or the browser becomes '' (term()).
     */
    #[Url(except: '')]
    public mixed $q = '';

    /**
     * Category chip (a category id); untyped, validated in render().
     */
    #[Url(except: null)]
    public mixed $category = null;

    #[Url(except: CatalogQuery::DEFAULT_SORT)]
    public mixed $sort = CatalogQuery::DEFAULT_SORT;

    public function mount(): void
    {
        $this->normalize();
    }

    public function updatedQ(): void
    {
        $this->category = null;
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->normalize();
        $this->resetPage();
    }

    /**
     * A chip click: the id is checked against the results in render().
     */
    public function selectCategory(mixed $id = null): void
    {
        $this->category = $id;
        $this->normalize();
        $this->resetPage();
    }

    public function render(): View
    {
        $this->normalize();
        $term = $this->term();
        $query = app(SearchProducts::class)->query($term);
        $products = null;
        $chips = [];

        if ($query !== null) {
            $chips = $this->chips($query);
            $categoryId = $this->category;

            // Not a category of the current results (tampered, stale or hidden): ignored.
            if ($categoryId !== null && ! in_array($categoryId, array_column($chips, 'id'), true)) {
                $this->category = $categoryId = null;
            }

            if ($categoryId !== null) {
                $query->where('products.category_id', $categoryId);
            }

            $products = CatalogQuery::applySort($query->with(ProductCards::EAGER_LOADS), $this->sort)
                ->paginate((int) config('site.per_page', 24))
                ->withPath(Livewire::originalUrl())
                ->appends(array_filter([
                    'q' => $term,
                    'category' => $categoryId,
                    'sort' => $this->sort !== CatalogQuery::DEFAULT_SORT ? $this->sort : null,
                ], fn (mixed $value): bool => $value !== null));

            // A page past the end doesn't exist.
            abort_if($products->currentPage() > max(1, $products->lastPage()), 404);
        }

        $empty = $products === null || $products->total() === 0;
        $company = app(CompanySettings::class);
        $telegram = $company->telegramUrl();

        return view('livewire.site.search', [
            'term' => $term,
            'products' => $products,
            'cards' => $products !== null ? ProductCards::fromProducts($products->items()) : [],
            // Chips only when they split the results (2+ categories).
            'chips' => count($chips) > 1 ? array_slice($chips, 0, self::MAX_CHIPS) : [],
            'chipsTotal' => array_sum(array_column($chips, 'count')),
            'sorts' => CatalogQuery::SORTS,
            'popularCategories' => $empty && $term !== '' ? $this->popularCategories() : [],
            // UQ2-11: tel: from digits only, Telegram only as https://t.me/….
            'phoneHref' => $company->phone() !== '' ? 'tel:+'.preg_replace('/\D+/', '', $company->phone()) : null,
            'phoneDisplay' => $company->phoneDisplay(),
            'telegramHref' => str_starts_with($telegram, 'https://t.me/') ? $telegram : null,
            'breadcrumbs' => [
                ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
                ['label' => __('site.search.title'), 'url' => null],
            ],
        ])
            ->title($term !== '' ? __('site.search.results_for', ['query' => $term]).' — '.__('site.meta.site_name') : __('site.search.title').' — '.__('site.meta.site_name'))
            ->layoutData([
                'description' => __('site.search.seo_description'),
                'robots' => 'noindex,follow',
            ]);
    }

    /**
     * Categories of the results with their counts (one grouped query),
     * visible ones only, most results first, then tree order. The selected
     * category is kept even past MAX_CHIPS.
     *
     * @param  Builder<Product>  $query
     * @return list<array{id: int, label: string, count: int}>
     */
    protected function chips(Builder $query): array
    {
        $counts = (clone $query)
            ->reorder()
            ->groupBy('products.category_id')
            ->selectRaw('products.category_id as category_id, count(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'category_id');

        $order = array_flip(CategoryTree::visibleIds());
        $chips = [];

        foreach ($counts as $id => $count) {
            $id = (int) $id;

            if (isset($order[$id])) {
                $chips[] = ['id' => $id, 'label' => CategoryTree::name($id), 'count' => (int) $count];
            }
        }

        usort($chips, fn (array $a, array $b): int => [$b['count'], $order[$a['id']]] <=> [$a['count'], $order[$b['id']]]);

        $selected = array_search($this->category, array_column($chips, 'id'), true);

        if ($selected !== false && $selected >= self::MAX_CHIPS) {
            $chips[self::MAX_CHIPS - 1] = $chips[$selected];
            $chips = array_slice($chips, 0, self::MAX_CHIPS);
        }

        return $chips;
    }

    /**
     * Up to POPULAR_CATEGORIES visible top-level categories with the most products.
     *
     * @return list<CategoryTile>
     */
    protected function popularCategories(): array
    {
        $tiles = array_filter(app(HomeContent::class)->categories(), fn (CategoryTile $tile): bool => ($tile->productsCount ?? 0) > 0);
        usort($tiles, fn (CategoryTile $a, CategoryTile $b): int => ($b->productsCount ?? 0) <=> ($a->productsCount ?? 0));

        return array_slice($tiles, 0, self::POPULAR_CATEGORIES);
    }

    /**
     * Wrong types from the URL or the browser become the defaults.
     */
    protected function normalize(): void
    {
        $category = is_int($this->category) || (is_string($this->category) && ctype_digit($this->category) && strlen($this->category) <= 10)
            ? (int) $this->category
            : null;
        $this->category = $category !== null && $category > 0 ? $category : null;
        $this->sort = is_string($this->sort) && in_array($this->sort, CatalogQuery::SORTS, true) ? $this->sort : CatalogQuery::DEFAULT_SORT;
    }

    /**
     * The query cut to MAX_LENGTH before any other work, whitespace collapsed.
     */
    protected function term(): string
    {
        return SearchProducts::term($this->q);
    }
}
