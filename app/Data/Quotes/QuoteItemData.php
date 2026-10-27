<?php

namespace App\Data\Quotes;

use App\Data\Catalog\RollLine;

/**
 * One quote line for CreateQuote / UpdateQuote.
 *
 * - itemId: an existing line of this quote (its name / sku / unit
 *   snapshots stay; qty, price and order change; a free-text line's name too).
 * - productId: a new product line; name / sku / unit are snapshots of the
 *   product, price defaults to the customer's level price.
 * - neither: a free-text line (e.g. a service) with the given name.
 * - price: per unit in tiyin including VAT; null = the level price (0 for free text).
 *
 * Label lines (plan 08): qty is rolls; pricePerLabel (tiyin, whole soʻm,
 * null = the level price) is what the manager edits, the per-roll price is
 * derived. A new label product line needs rollTypeId (attached and active);
 * a saved line keeps its roll snapshot. rollSnapshot copies a roll line as
 * free text (DuplicateQuote, when its product or roll type is gone).
 */
final readonly class QuoteItemData
{
    public function __construct(
        public ?int $productId = null,
        public ?string $name = null,
        public int $qty = 1,
        public ?int $price = null,
        public ?int $itemId = null,
        public ?int $rollTypeId = null,
        public ?int $pricePerLabel = null,
        public ?RollLine $rollSnapshot = null,
    ) {}
}
