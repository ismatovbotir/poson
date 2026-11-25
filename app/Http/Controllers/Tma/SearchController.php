<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Chat\SearchProducts;
use App\Support\Catalog\ProductCards;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tma/{locale}/search?q= (plan 20 §3, A8): the site's product search
 * (SearchProducts: every word of the query in SKU, model, names, brand),
 * PER_PAGE results per page. No AI.
 */
class SearchController extends TmaController
{
    public const PER_PAGE = 20;

    public function __invoke(Request $request, SearchProducts $search): Response
    {
        $term = SearchProducts::term($request->query('q', ''));
        $query = $search->query($term);
        $products = null;

        if ($query !== null) {
            $products = $query->with(ProductCards::EAGER_LOADS)
                ->paginate(self::PER_PAGE)
                ->withPath(route('tma.search'))
                ->appends(['q' => $term]);

            abort_if($products->currentPage() > max(1, $products->lastPage()), 404);
        }

        return $this->page($request, 'tma.search', [
            'title' => __('tma.search.title'),
            'back' => route('tma.home'),
            'term' => $term,
            'products' => $products,
            'cards' => $products !== null ? ProductCards::fromProducts($products->items()) : [],
        ]);
    }
}
