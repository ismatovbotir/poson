<?php

namespace App\Http\Controllers\Tma;

use App\Support\Catalog\CategoryTree;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tma/{locale} (plan 20 §3): the logo, search, the visible top-level
 * categories (CategoryTree, cached), "My orders" and, while the cart is on,
 * the cart. No banners, no HomeCache, no analytics.
 */
class HomeController extends TmaController
{
    public function __invoke(Request $request): Response
    {
        return $this->page($request, 'tma.home', [
            'categories' => array_map(fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('tma.category', ['category' => $node['slug']]),
            ], CategoryTree::visibleChildren(null)),
        ]);
    }
}
