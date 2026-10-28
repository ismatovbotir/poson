<?php

namespace App\Support\Cart;

use App\Data\Catalog\RollLine;
use App\Enums\StockStatus;
use App\Models\Product;

/**
 * One cart line priced by CartPricer at the Retail price (integer tiyin).
 *
 * - lineKey: "p12" / "p12-r3" (CartLineKey), the only reference the page uses;
 * - rollTypeId: the roll type of a label product line (null otherwise);
 * - rollLine: the RollPricing line of a label product (qty = rolls);
 * - unitPrice / total: per piece (per roll) and for the line; null while the
 *   line can't be bought (never shown then);
 * - isValid: the line can be bought as it is; otherwise problem says why:
 *   PROBLEM_UNAVAILABLE (inactive, quote-only, no price, out of stock, roll
 *   type detached or hidden: "No longer available", must be removed) or
 *   PROBLEM_QUANTITY (below min_qty / above the cap: fix the quantity).
 * - quotable: the line can still go into "Request a quote for this cart"
 *   (the product and its roll type are still on the site).
 */
final readonly class PricedLine
{
    public const PROBLEM_UNAVAILABLE = 'unavailable';

    public const PROBLEM_QUANTITY = 'quantity';

    public function __construct(
        public string $lineKey,
        public Product $product,
        public ?int $rollTypeId,
        public ?RollLine $rollLine,
        public int $qty,
        public int $minQty,
        public ?int $unitPrice,
        public ?int $total,
        public bool $isValid,
        public ?string $problem,
        public bool $quotable,
    ) {}

    public function isRollLine(): bool
    {
        return $this->rollLine !== null;
    }

    public function isUnavailable(): bool
    {
        return $this->problem === self::PROBLEM_UNAVAILABLE;
    }

    /**
     * Sold on order: the lead time is confirmed by the manager.
     */
    public function isOnOrder(): bool
    {
        return $this->product->stock_status === StockStatus::OnOrder;
    }
}
