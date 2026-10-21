<?php

namespace App\Livewire\Site;

use App\Models\Product;
use App\Support\Catalog\ProductCards;
use App\Support\Search as SearchText;
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
 * Never indexed (noindex,follow; search URLs are disallowed in robots.txt).
 */
class Search extends Component
{
    use WithPagination;

    public const MAX_LENGTH = 100;

    /**
     * Words of the query that must all match.
     */
    public const MAX_WORDS = 5;

    /**
     * Untyped: a wrong type from the URL or the browser becomes '' (term()).
     */
    #[Url(except: '')]
    public mixed $q = '';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = $this->term();
        $words = $this->words($term);
        $products = null;

        if ($words !== []) {
            $products = Product::query()
                ->visible()
                ->where(function (Builder $query) use ($words): void {
                    foreach ($words as $word) {
                        $query->whereRaw("search_text like ? escape '!'", [SearchText::normalizedPattern($word)]);
                    }
                })
                ->with(ProductCards::EAGER_LOADS)
                ->orderByDesc('products.is_featured')
                ->orderBy('products.sort')
                ->orderBy('products.id')
                ->paginate((int) config('site.per_page', 24))
                ->withPath(Livewire::originalUrl())
                ->appends(['q' => $term]);

            // A page past the end doesn't exist.
            abort_if($products->currentPage() > max(1, $products->lastPage()), 404);
        }

        return view('livewire.site.search', [
            'term' => $term,
            'products' => $products,
            'cards' => $products !== null ? ProductCards::fromProducts($products->items()) : [],
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
     * The query cut to MAX_LENGTH before any other work, whitespace collapsed.
     */
    protected function term(): string
    {
        $query = is_string($this->q) ? mb_substr($this->q, 0, self::MAX_LENGTH * 2) : '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, self::MAX_LENGTH);
    }

    /**
     * @return list<string>
     */
    protected function words(string $term): array
    {
        $words = array_filter(
            explode(' ', SearchText::normalize($term)),
            fn (string $word): bool => $word !== '',
        );

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }
}
