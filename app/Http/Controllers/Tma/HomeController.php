<?php

namespace App\Http\Controllers\Tma;

use App\Support\Catalog\CategoryTree;
use App\Support\Telegram\MiniApp\TmaIcons;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tma/{locale} (plan 20 §3, plan 45 §2): the logo, search, the visible
 * top-level categories with their icons (CategoryTree, cached), the
 * "Why Poson" service card, "My orders" and, while the cart is on, the cart
 * bar. No banners, no HomeCache, no analytics.
 */
class HomeController extends TmaController
{
    public function __invoke(Request $request): Response
    {
        return $this->page($request, 'tma.home', [
            'categories' => array_map(fn (array $node): array => [
                'label' => CategoryTree::name($node['id']),
                'url' => route('tma.category', ['category' => $node['slug']]),
                'icon' => TmaIcons::name($node['icon'] ?? null),
            ], CategoryTree::visibleChildren(null)),
            'cartBar' => $this->cartSummary($request),
        ]);
    }
}
