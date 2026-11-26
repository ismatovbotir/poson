<?php

namespace App\Support\Telegram\MiniApp;

use App\Support\Catalog\CategoryTree;

/**
 * Where a cart form sent without JavaScript returns to (plan 45 §3, MA-46).
 * The form names its screen in "from", one closed grammar; the URL is built
 * here from route names and checked ids, never from back(), the Referer or
 * a URL in the request:
 *
 *  - c{id} / c{id}-{page}  a visible category listing (page 2…9999) + "#p-{product id}";
 *  - p                     the product page;
 *  - cart                  the cart.
 *
 * Anything else (or a hidden category) is the action's default screen.
 */
final class TmaReturn
{
    public const PATTERN = '/\A(cart|p|c[1-9]\d{0,9}(?:-[1-9]\d{0,3})?)\z/';

    public const CART = 'cart';

    public const PRODUCT = 'p';

    public static function isValid(mixed $from): bool
    {
        return is_string($from) && preg_match(self::PATTERN, $from) === 1;
    }

    /**
     * "c{id}" or "c{id}-{page}" for a listing page (page 1 has no suffix).
     */
    public static function category(int $categoryId, int $page = 1): string
    {
        return 'c'.$categoryId.($page > 1 ? '-'.min($page, 9999) : '');
    }

    /**
     * @param  string|null  $productSlug  the product's slug when it is shown on the site
     */
    public static function url(mixed $from, int $productId, ?string $productSlug, string $default): string
    {
        if (! self::isValid($from)) {
            return $default;
        }

        if ($from === self::CART) {
            return route('tma.cart');
        }

        if ($from === self::PRODUCT) {
            return $productSlug !== null ? route('tma.product', ['product' => $productSlug]) : route('tma.cart');
        }

        [$categoryId, $page] = array_map('intval', explode('-', substr((string) $from, 1)) + [1 => 1]);

        if (! CategoryTree::isVisible($categoryId)) {
            return $default;
        }

        return route('tma.category', array_filter([
            'category' => CategoryTree::find($categoryId)['slug'],
            'page' => $page > 1 ? $page : null,
        ])).'#p-'.$productId;
    }
}
