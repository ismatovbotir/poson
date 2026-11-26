<?php

namespace App\Data\Telegram;

use App\Data\Site\ProductCard;

/**
 * A product on a Mini App listing (plan 45 §3): the site card (name, Retail
 * price, stock, key specs, image or diagram; its site URLs are never
 * rendered) plus what the card's own action needs:
 *
 *  - cartKind KIND_CART: "Add to cart" with qty = minQty, a stepper once in the cart;
 *  - KIND_ROLL: a label product, "Choose roll" on the product page (no silent default);
 *  - KIND_QUOTE: quote / contact / service, also out of stock or without a price.
 *
 * lineKey is CartLineKey::make(id) for KIND_CART only; inCartQty comes from
 * the user's open cart (TmaCartSummary), 0 when not in it. icon: the
 * category's icon (nearest ancestor with one) for the image placeholder.
 * Not cached anywhere (prices and stock are read per request).
 */
final readonly class TmaProductCard
{
    public const KIND_CART = 'cart';

    public const KIND_ROLL = 'roll';

    public const KIND_QUOTE = 'quote';

    public function __construct(
        public ProductCard $card,
        public int $id,
        public int $minQty,
        public string $cartKind,
        public int $inCartQty,
        public ?string $lineKey,
        public string $icon,
        public string $actionLabel,
    ) {}
}
