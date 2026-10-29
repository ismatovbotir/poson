<?php

namespace App\Data\Orders;

/**
 * One order line for the staff order actions (CreateManualOrder,
 * UpdateOrderLines).
 *
 * - itemId: an existing line of this order (its snapshots stay; qty and, on
 *   a manual order, the price change);
 * - productId: a new product line (snapshots of the product; price at the
 *   order's price level, Retail on a site order);
 * - neither: a free-text line with a name (manual orders only).
 * - price: per unit in tiyin including VAT; null = the level price (or the
 *   saved one). Ignored on site orders: they are always priced from the
 *   catalog (security condition 26).
 * - pricePerLabel (tiyin, whole soʻm): a label line's editable price; a new
 *   label line needs rollTypeId.
 */
final readonly class OrderLineData
{
    public function __construct(
        public ?int $productId = null,
        public ?string $name = null,
        public int $qty = 1,
        public ?int $price = null,
        public ?int $itemId = null,
        public ?int $rollTypeId = null,
        public ?int $pricePerLabel = null,
    ) {}
}
