<?php

namespace App\Livewire\Site\Brands;

use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\HttpsUrl;
use App\Support\Catalog\ProductCards;
use App\Support\HtmlSanitizer;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/**
 * Brand page (decision 2026-10-24) at /{locale}/brands/{slug}: logo,
 * description, the official website (Brand::publicWebsite(): never a
 * software brand's partner link, which only the products' tracked "Try"
 * button uses), then the brand's products visible on the site as product
 * cards, paginated like the catalog and in its default order.
 *
 * Category filter: the top-level catalog sections the brand's products are
 * in (one grouped query, names from the cached CategoryTree), shown when
 * there are two or more; ?category={slug} keeps the section and its
 * subcategories. A filtered page or a brand without products is
 * noindex,follow; canonical and hreflang keep the clean URL (page only).
 *
 * An inactive or unknown brand is a 404. Brand + BreadcrumbList JSON-LD;
 * the OG image is the logo.
 */
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public Brand $brand;

    /**
     * Slug of a top-level category (sanitized in render()).
     */
    #[Url(except: '')]
    public mixed $category = '';

    public function mount(Brand $brand): void
    {
        abort_unless($brand->is_active, 404);

        $this->brand = $brand;
    }

    /**
     * Update requests: the brand may have been hidden since the page loaded
     * (the locked model is re-read from the database on every request).
     */
    public function hydrate(): void
    {
        abort_unless($this->brand->is_active, 404);
    }

    public function filterCategory(string $slug = ''): void
    {
        $this->category = $slug;
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function render(StructuredData $structuredData): View
    {
        $brand = $this->brand->load('media');
        $sections = $this->sections();
        $selected = is_string($this->category) && isset($sections[$this->category]) ? $sections[$this->category] : null;
        $this->category = $selected !== null ? $this->category : '';

        $query = Product::query()
            ->visible()
            ->where('products.brand_id', $brand->id)
            ->with(ProductCards::EAGER_LOADS)
            ->orderByDesc('products.is_featured')
            ->orderBy('products.sort')
            ->orderBy('products.id');

        if ($selected !== null) {
            $query->whereIn('products.category_id', CategoryTree::visibleSubtreeIds($selected['id']));
        }

        $paginate = fn () => (clone $query)->paginate((int) config('site.per_page', 24))
            ->withPath(Livewire::originalUrl())
            ->appends(array_filter(['category' => $this->category]));
        $products = $paginate();

        if ($products->currentPage() > max(1, $products->lastPage())) {
            // A page past the end (old link, typed URL) doesn't exist; on an
            // update (products hidden since the page loaded) go back to page 1.
            abort_unless(Livewire::isLivewireRequest(), 404);
            $this->resetPage();
            $products = $paginate();
        }

        $locale = app()->getLocale();
        $url = route('site.brands.show', ['brand' => $brand->slug]);
        $logo = $brand->imageUrl('large');
        $description = HtmlSanitizer::clean((string) $brand->getTranslation('description', $locale));
        $plainDescription = SeoText::description($description);
        $website = $brand->publicWebsite();
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.brands.title'), 'url' => route('site.brands.index')],
            ['label' => $brand->name, 'url' => null],
        ];

        return view('livewire.site.brands.show', [
            'products' => $products,
            'cards' => ProductCards::fromProducts($products->items()),
            'sections' => count($sections) > 1 ? array_values($sections) : [],
            'breadcrumbs' => $breadcrumbs,
            'logo' => $brand->imageUrl('thumb'),
            'description' => $description,
            'website' => $website,
            'websiteHost' => HttpsUrl::host($website),
            'jsonLd' => [
                StructuredData::toScript($structuredData->brand($brand->name, $url, $logo, $plainDescription, $website)),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
            ],
        ])
            ->title(__('site.brands.show.seo_title', ['name' => $brand->name]))
            ->layoutData(array_filter([
                'description' => $plainDescription ?? __('site.brands.show.seo_description', ['name' => $brand->name]),
                'image' => $logo,
                'robots' => $selected !== null || $products->total() === 0 ? 'noindex,follow' : null,
            ]));
    }

    /**
     * Top-level catalog sections of the brand's visible products, in admin
     * order, keyed by slug, with the product count (subcategories rolled up).
     *
     * @return array<string, array{id: int, slug: string, label: string, count: int, checked: bool}>
     */
    protected function sections(): array
    {
        $counts = Product::query()
            ->visible()
            ->where('products.brand_id', $this->brand->id)
            ->groupBy('products.category_id')
            ->selectRaw('products.category_id as category_id, count(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'category_id');

        $totals = [];

        foreach ($counts as $categoryId => $count) {
            $ancestors = CategoryTree::ancestorIds((int) $categoryId);
            $rootId = $ancestors !== [] ? end($ancestors) : (int) $categoryId;
            $totals[$rootId] = ($totals[$rootId] ?? 0) + (int) $count;
        }

        $sections = [];

        foreach (CategoryTree::visibleChildren(null) as $node) {
            if (isset($totals[$node['id']])) {
                $sections[$node['slug']] = [
                    'id' => $node['id'],
                    'slug' => $node['slug'],
                    'label' => CategoryTree::name($node['id']),
                    'count' => $totals[$node['id']],
                    'checked' => $this->category === $node['slug'],
                ];
            }
        }

        return $sections;
    }
}
