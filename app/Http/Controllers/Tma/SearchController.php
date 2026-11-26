<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Chat\SearchProducts;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;
use App\Support\Telegram\MiniApp\TmaCards;
use App\Support\Telegram\MiniApp\TmaCartSummary;
use App\Support\Telegram\MiniApp\TmaIcons;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tma/{locale}/search?q= (plan 20 §3, A8): the site's product search
 * (SearchProducts: every word of the query in SKU, model, names, brand),
 * PER_PAGE results per page. No AI. Plan 45: cards with their cart action,
 * the cart bar, and the top-level categories as chips in the hint and
 * no-results states.
 */
class SearchController extends TmaController
{
    public const PER_PAGE = 20;

    public function __invoke(Request $request, SearchProducts $search): Response
    {
        $term = SearchProducts::term($request->query('q', ''));
        $query = $search->query($term);
        $products = null;
        $cart = $this->cartSummary($request);

        if ($query !== null) {
            $products = $query->with(ProductCards::EAGER_LOADS)
                ->paginate(self::PER_PAGE)
                ->withPath(route('tma.search'))
                ->appends(['q' => $term]);

            abort_if($products->currentPage() > max(1, $products->lastPage()), 404);
        }

        $cards = $products !== null ? TmaCards::fromProducts($products->items(), $cart ?? new TmaCartSummary) : [];

        return $this->page($request, 'tma.search', [
            'title' => __('tma.search.title'),
            'back' => route('tma.home'),
            'term' => $term,
            'products' => $products,
            'cards' => $cards,
            'categories' => $cards === [] ? array_map(fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('tma.category', ['category' => $node['slug']]),
                'icon' => TmaIcons::name($node['icon'] ?? null),
            ], CategoryTree::visibleChildren(null)) : [],
            'cartBar' => $cart,
        ]);
    }
}
