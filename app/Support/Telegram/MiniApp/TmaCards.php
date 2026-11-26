<?php

namespace App\Support\Telegram\MiniApp;

use App\Data\Site\ProductCta;
use App\Data\Telegram\TmaProductCard;
use App\Enums\ProductCtaKind;
use App\Models\Product;
use App\Support\Cart\CartLineKey;
use App\Support\Cart\CartPricer;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductCards;

/**
 * Mini App listing cards (plan 45 §3): ProductCards::fromProducts() for the
 * card data (no extra queries with ProductCards::EAGER_LOADS), the action
 * kind from ProductCtas (the one source of CTAs), the in-cart quantity from
 * the cart summary already loaded for the cart bar (no query either).
 */
final class TmaCards
{
    /**
     * @param  list<Product>  $products
     * @return list<TmaProductCard>
     */
    public static function fromProducts(array $products, TmaCartSummary $cart): array
    {
        $cards = ProductCards::fromProducts($products);
        $items = [];

        foreach (array_values($products) as $index => $product) {
            $card = $cards[$index];
            $kinds = array_map(fn (ProductCta $cta): ProductCtaKind => $cta->kind, $card->ctas);
            $kind = match (true) {
                ($kinds[0] ?? null) === ProductCtaKind::Cart => $card->pricedPerLabel ? TmaProductCard::KIND_ROLL : TmaProductCard::KIND_CART,
                default => TmaProductCard::KIND_QUOTE,
            };
            $lineKey = $kind === TmaProductCard::KIND_CART ? CartLineKey::make($product->id) : null;
            $inquiry = collect($kinds)->first(fn (ProductCtaKind $cta): bool => in_array($cta, [ProductCtaKind::Quote, ProductCtaKind::Contact, ProductCtaKind::ServiceRequest], true));

            $items[] = new TmaProductCard(
                card: $card,
                id: $product->id,
                minQty: CartPricer::minQty($product),
                cartKind: $kind,
                inCartQty: $cart->qty($lineKey),
                lineKey: $lineKey,
                icon: self::icon($product->category_id),
                actionLabel: ($inquiry ?? ProductCtaKind::Quote)->label(),
            );
        }

        return $items;
    }

    /**
     * The icon of the category or its nearest ancestor with one (TmaIcons falls back to "grid").
     */
    public static function icon(int $categoryId): string
    {
        foreach ([$categoryId, ...CategoryTree::ancestorIds($categoryId)] as $id) {
            $icon = CategoryTree::find($id)['icon'] ?? null;

            if (is_string($icon) && $icon !== '') {
                return TmaIcons::name($icon);
            }
        }

        return TmaIcons::FALLBACK;
    }
}
