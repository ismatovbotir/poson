<?php

namespace App\Livewire\Site;

use App\Actions\Chat\SearchProducts;
use App\Support\Catalog\ProductCards;
use Illuminate\Contracts\View\View;
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
 */
class Search extends Component
{
    use WithPagination;

    public const MAX_LENGTH = SearchProducts::MAX_LENGTH;

    /**
     * Words of the query that must all match.
     */
    public const MAX_WORDS = SearchProducts::MAX_WORDS;

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
        $query = app(SearchProducts::class)->query($term);
        $products = null;

        if ($query !== null) {
            $products = $query
                ->with(ProductCards::EAGER_LOADS)
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
        return SearchProducts::term($this->q);
    }
}
