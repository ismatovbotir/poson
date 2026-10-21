<?php

namespace App\Data\Site;

use App\Enums\ProductBadge;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use InvalidArgumentException;

/**
 * Product card on listings (App\Support\Catalog\ProductCards::fromProduct()):
 * name and key-spec line in the current locale, Retail price in tiyin (null =
 * price on request), stock status, sale mode and an optional "hit" / "new"
 * badge. image is a public conversion URL; without one the card draws the
 * sprite symbol "placeholder" (p-scanner, p-printer, ...).
 *
 * Brand rule: there is no old price and no sale badge, by design.
 */
final readonly class ProductCard
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $sku,
        public string $specs,
        public ?int $price,
        public StockStatus $stockStatus,
        public SaleMode $saleMode,
        public ?string $image,
        public string $url,
        public ?ProductBadge $badge = null,
        public ?string $brand = null,
        public ?string $category = null,
        public string $placeholder = 'p-barcode',
        public ?string $imageAlt = null,
    ) {
        if ($price !== null && $price < 0) {
            throw new InvalidArgumentException('Product price must not be negative.');
        }
    }

    /**
     * The product allows "Add to cart": cart sale mode and a known price.
     * The site shows the button only while config('site.cart_enabled') is on.
     */
    public function canAddToCart(): bool
    {
        return $this->saleMode->allowsCart() && $this->price !== null;
    }
}
