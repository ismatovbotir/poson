<?php

namespace App\Livewire\Site\Catalog;

use App\Data\Site\NewsCard;
use App\Models\Category;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;
use App\Support\Site\BannerDirectory;
use App\Support\Site\FaqContent;
use App\Support\Site\NewsCards;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/**
 * Catalog listing (plan 03 T17): /{locale}/catalog (every published product)
 * and /{locale}/catalog/{category:slug} (the category and its visible
 * subcategories). Filters, sort and page live in the URL; the querying is
 * App\Support\Catalog\CatalogQuery, this component only holds the state.
 *
 * A hidden category (inactive itself or under an inactive parent) is a 404.
 * Canonical and hreflang use the clean URL (page only); more than one filter
 * value or a sort gives noindex,follow.
 *
 * A category's first live banner (plan 07 7c, K13) shows under the heading
 * on page 1 only.
 */
class Index extends Component
{
    use WithPagination;

    /**
     * @var list<string>
     */
    public const FILTERS = ['brand', 'stock', 'priceMin', 'priceMax', 'f'];

    #[Locked]
    public ?Category $category = null;

    /*
     * Filter state comes from the URL and the browser, so the properties are
     * untyped: normalize() turns wrong types into empty values instead of a
     * TypeError, and CatalogQuery validates the contents.
     */

    /**
     * Brand slugs.
     *
     * @var list<string>
     */
    #[Url(except: [])]
    public mixed $brand = [];

    /**
     * StockStatus values.
     *
     * @var list<string>
     */
    #[Url(except: [])]
    public mixed $stock = [];

    /**
     * Whole soʻm (digits only).
     */
    #[Url(as: 'price_min', except: '')]
    public mixed $priceMin = '';

    #[Url(as: 'price_max', except: '')]
    public mixed $priceMax = '';

    /**
     * Attribute filters: f[code][] option ids or "1", f[code][min|max] for numbers.
     *
     * @var array<string, mixed>
     */
    #[Url(except: [])]
    public mixed $f = [];

    #[Url(except: CatalogQuery::DEFAULT_SORT)]
    public mixed $sort = CatalogQuery::DEFAULT_SORT;

    public function mount(?Category $category = null): void
    {
        if ($category !== null) {
            abort_unless(CategoryTree::isVisible($category->id), 404);
        }

        $this->category = $category;
        $this->normalize();
    }

    /**
     * Update requests: the category may have been hidden since the page loaded.
     */
    public function hydrate(): void
    {
        if ($this->category !== null) {
            abort_unless(CategoryTree::isVisible($this->category->id), 404);
        }
    }

    public function updated(string $property): void
    {
        $this->normalize();

        if (in_array(explode('.', $property)[0], [...self::FILTERS, 'sort'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Wrong types become empty values; prices keep their digits only.
     */
    protected function normalize(): void
    {
        foreach (['brand', 'stock'] as $list) {
            $this->{$list} = is_array($this->{$list})
                ? array_values(array_filter(array_slice($this->{$list}, 0, 50), fn (mixed $value): bool => is_string($value) && $value !== ''))
                : [];
        }

        foreach (['priceMin', 'priceMax'] as $price) {
            $value = $this->{$price};
            $this->{$price} = is_scalar($value) ? substr((string) preg_replace('/\D+/', '', (string) $value), 0, 15) : '';
        }

        $this->f = is_array($this->f) ? array_slice($this->f, 0, 50, true) : [];
        $this->sort = is_string($this->sort) && in_array($this->sort, CatalogQuery::SORTS, true) ? $this->sort : CatalogQuery::DEFAULT_SORT;
    }

    public function toggleBrand(string $slug): void
    {
        $this->normalize();
        $this->brand = $this->toggle($this->brand, $slug);
        $this->resetPage();
    }

    public function toggleStock(string $value): void
    {
        $this->normalize();
        $this->stock = $this->toggle($this->stock, $value);
        $this->resetPage();
    }

    /**
     * Toggle an option of a select / multiselect filter, or ("1") a boolean filter.
     */
    public function toggleValue(string $code, string $value): void
    {
        $this->normalize();
        $current = is_array($this->f[$code] ?? null) ? $this->f[$code] : [];
        $values = $this->toggle(array_values(array_filter($current, 'is_scalar')), $value);

        $filters = $this->f;

        if ($values === []) {
            unset($filters[$code]);
        } else {
            $filters[$code] = $values;
        }

        $this->f = $filters;

        $this->resetPage();
    }

    /**
     * Remove one active filter (the chips): brand / stock / option with a value,
     * price_min / price_max, or a whole attribute.
     */
    public function removeFilter(string $type, string $key = '', string $value = ''): void
    {
        $this->normalize();
        match ($type) {
            'brand' => $this->brand = $this->without($this->brand, $key),
            'stock' => $this->stock = $this->without($this->stock, $key),
            'price_min' => $this->priceMin = '',
            'price_max' => $this->priceMax = '',
            'option' => $this->toggleValue($key, $value),
            'attribute' => $this->forgetAttribute($key),
            default => null,
        };

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function render(StructuredData $structuredData, FaqContent $faqContent): View
    {
        $query = new CatalogQuery(
            category: $this->category,
            brand: $this->brand,
            stock: $this->stock,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            attributeFilters: $this->f,
            sort: $this->sort,
        );

        $products = $query->paginate((int) config('site.per_page', 24))
            ->withPath(Livewire::originalUrl())
            ->appends($query->queryParameters());

        // A page past the end (old link, typed URL) doesn't exist.
        abort_if($products->currentPage() > max(1, $products->lastPage()), 404);

        $breadcrumbs = $this->breadcrumbs();
        // A category's FAQ, or its nearest ancestor's (plan 04 T12); none on /catalog.
        // FAQPage JSON-LD only for the category's own items, on the first page.
        $faq = $this->category !== null ? $faqContent->forCategory($this->category->id) : ['items' => [], 'own' => false];
        $faqMarkup = $faq['own'] && $faq['items'] !== [] && $products->currentPage() === 1;
        $currentUrl = $this->category !== null
            ? route('site.catalog.category', ['category' => $this->category->slug])
            : route('site.catalog.index');

        return view('livewire.site.catalog.index', [
            'products' => $products,
            'cards' => ProductCards::fromProducts($products->items()),
            'facets' => $query->facets(),
            'chips' => $query->activeFilters(),
            'activeCount' => $query->activeFilterCount(),
            'subcategories' => $this->subcategories(),
            'breadcrumbs' => $breadcrumbs,
            'heading' => $this->category !== null ? (string) $this->category->name : __('site.catalog.title'),
            'banner' => $this->category !== null && $products->currentPage() === 1 ? BannerDirectory::forCategory($this->category->id) : null,
            'sorts' => CatalogQuery::SORTS,
            'breadcrumbsJson' => StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $currentUrl)),
            'faq' => $faq['items'],
            'articles' => $this->articles,
            'faqJson' => $faqMarkup ? StructuredData::toScript($structuredData->faqPage($faq['items'])) : null,
        ])
            ->title($this->seoTitle())
            ->layoutData([
                'description' => $this->seoDescription(),
                'robots' => $query->robots(),
            ]);
    }

    /**
     * "Useful articles": up to 3 visible articles linked to this category,
     * its ancestors or its descendants (two queries: posts and media; none
     * while the locale has no articles). Computed once per page and kept for
     * the filter, sort and pager updates of the same page (10 minutes).
     *
     * @return list<NewsCard>
     */
    #[Computed(persist: true, seconds: 600)]
    public function articles(): array
    {
        return $this->category !== null ? NewsCards::articlesFor($this->category->id) : [];
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    protected function breadcrumbs(): array
    {
        $items = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.catalog.title'), 'url' => $this->category !== null ? route('site.catalog.index') : null],
        ];

        if ($this->category !== null) {
            foreach (array_reverse(CategoryTree::ancestorIds($this->category->id)) as $ancestorId) {
                $items[] = [
                    'label' => CategoryTree::name($ancestorId),
                    'url' => route('site.catalog.category', ['category' => CategoryTree::find($ancestorId)['slug']]),
                ];
            }

            $items[] = ['label' => (string) $this->category->name, 'url' => null];
        }

        return $items;
    }

    /**
     * Visible subcategories (the top-level categories on the catalog index).
     *
     * @return list<array{label: string, url: string}>
     */
    protected function subcategories(): array
    {
        return array_map(
            fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('site.catalog.category', ['category' => $node['slug']]),
            ],
            CategoryTree::visibleChildren($this->category?->id),
        );
    }

    protected function seoTitle(): string
    {
        if ($this->category === null) {
            return __('site.catalog.seo.title');
        }

        return SeoText::title($this->category->getTranslation('meta_title', app()->getLocale(), false), (string) $this->category->name);
    }

    protected function seoDescription(): string
    {
        if ($this->category === null) {
            return __('site.catalog.seo.description');
        }

        $locale = app()->getLocale();

        return SeoText::description(
            $this->category->getTranslation('meta_description', $locale, false),
            $this->category->getTranslation('description', $locale, false),
        ) ?? __('site.catalog.seo.category_description', ['name' => (string) $this->category->name]);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function toggle(array $values, string $value): array
    {
        $values = array_values(array_filter(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $values)));

        return in_array($value, $values, true) ? $this->without($values, $value) : [...$values, $value];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function without(array $values, string $value): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $values),
            fn (string $item): bool => $item !== '' && $item !== $value,
        ));
    }

    private function forgetAttribute(string $code): void
    {
        $filters = $this->f;
        unset($filters[$code]);
        $this->f = $filters;
    }
}
