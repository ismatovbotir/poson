<?php

namespace App\Support\Cart;

use App\Actions\Quotes\SaveQuoteItems;
use App\Data\Catalog\RollLine;
use App\Enums\PriceLevel;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * Prices a cart on every request (plan 11 v2 §1.1, security conditions 6,
 * 7 and 17). The cart stores no prices: each line is re-read from the
 * product at the Retail price only (PriceLevel::Retail is hard-coded;
 * Dealer / Wholesale are never read, and no price ever comes from the
 * browser).
 *
 * - a product line: Retail price × qty;
 * - a label product line: RollPricing::line($product, $rollType, $rolls,
 *   null, PriceLevel::Retail), i.e. per-roll price × rolls.
 *
 * A line stays in the cart when it can no longer be bought (product hidden
 * or deleted, quote-only, no Retail price, out of stock, roll type detached
 * or inactive): it is flagged unavailable and left out of the subtotal
 * until the buyer removes it. evaluate() holds the rules and is shared with
 * the cart actions, so adding and pricing never disagree.
 */
class CartPricer
{
    public function price(Cart $cart): PricedCart
    {
        $items = $cart->items()
            ->with([
                'product' => fn (Relation $query) => $query->withTrashed()->with([
                    'media',
                    'rollTypes',
                    // Only the label size values (RollPricing::labelSize()).
                    'attributeValues' => fn (Relation $values) => $values
                        ->whereHas('attribute', fn (Builder $attribute) => $attribute->whereIn('code', [RollPricing::WIDTH_CODE, RollPricing::HEIGHT_CODE]))
                        ->with('attribute'),
                ]),
            ])
            ->get();

        $lines = [];

        /** @var CartItem $item */
        foreach ($items as $item) {
            $product = $item->getRelationValue('product');

            // Deleted for good (the FK cascade removes the row; this only covers a race).
            if (! $product instanceof Product) {
                continue;
            }

            $rollType = $item->roll_type_id !== null ? $product->rollTypes->firstWhere('id', $item->roll_type_id) : null;
            $result = static::evaluate($product, $item->roll_type_id, $rollType, $item->qty);

            $lines[] = new PricedLine(
                lineKey: $item->line_key,
                product: $product,
                rollTypeId: $item->roll_type_id,
                rollLine: $result['rollLine'],
                qty: $item->qty,
                minQty: static::minQty($product),
                unitPrice: $result['unitPrice'],
                total: $result['problem'] === null ? $result['total'] : null,
                isValid: $result['problem'] === null,
                problem: $result['problem'],
                quotable: $result['quotable'],
            );
        }

        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line->isValid ? (int) $line->total : 0;
        }

        return new PricedCart($lines, $subtotal, static::fingerprint($lines));
    }

    /**
     * Whether a product (in a roll type) and quantity can be bought, and at what Retail price.
     *
     * @param  int|null  $rollTypeId  the requested roll type id (a label product line)
     * @param  RollType|null  $rollType  that roll type, when it exists
     * @return array{problem: string|null, rollLine: RollLine|null, unitPrice: int|null, total: int|null, quotable: bool}
     */
    public static function evaluate(?Product $product, ?int $rollTypeId, ?RollType $rollType, int $qty): array
    {
        $unavailable = fn (bool $quotable = false): array => ['problem' => PricedLine::PROBLEM_UNAVAILABLE, 'rollLine' => null, 'unitPrice' => null, 'total' => null, 'quotable' => $quotable];

        if (! $product instanceof Product || $product->trashed() || ! $product->isVisible()) {
            return $unavailable();
        }

        // Label products only in an attached, active roll type; other products never with one.
        if ($product->isPricedPerLabel()) {
            if ($rollTypeId === null || ! $rollType instanceof RollType || $rollType->id !== $rollTypeId || ! RollPricing::isAvailable($product, $rollType)) {
                return $unavailable();
            }
        } elseif ($rollTypeId !== null) {
            return $unavailable();
        }

        // Still on the site: a quote can be requested for it from here on.
        $retail = $product->priceFor(PriceLevel::Retail);

        if ($retail === null || $retail <= 0 || ! $product->effectiveSaleMode()->allowsCart() || ! static::isStocked($product)) {
            return $unavailable(true);
        }

        $rollLine = null;

        if ($product->isPricedPerLabel()) {
            try {
                // Retail only (brand rule): no per-label override, never Dealer / Wholesale.
                $rollLine = RollPricing::line($product, $rollType, max(1, $qty), null, PriceLevel::Retail);
            } catch (ValidationException) {
                return $unavailable(true);
            }

            $unitPrice = (int) $rollLine->rollPrice;
        } else {
            $unitPrice = $retail;
        }

        if ($unitPrice > SaveQuoteItems::MAX_PRICE) {
            return $unavailable(true);
        }

        $problem = $qty < static::minQty($product) || $qty > Cart::MAX_QTY ? PricedLine::PROBLEM_QUANTITY : null;

        return [
            'problem' => $problem,
            'rollLine' => $rollLine,
            'unitPrice' => $unitPrice,
            // MAX_PRICE × MAX_QTY = 1e17 stays below PHP_INT_MAX.
            'total' => $problem === null ? $unitPrice * $qty : null,
            'quotable' => true,
        ];
    }

    /**
     * In stock or on order (the manager confirms the lead time); out of stock can't be bought.
     */
    public static function isStocked(Product $product): bool
    {
        return in_array($product->stock_status, [StockStatus::InStock, StockStatus::OnOrder], true);
    }

    /**
     * The product's minimum (pieces or rolls), within 1..Cart::MAX_QTY.
     */
    public static function minQty(Product $product): int
    {
        return max(1, min(Cart::MAX_QTY, (int) $product->min_qty));
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    public static function fingerprint(array $lines): string
    {
        $parts = array_map(
            fn (PricedLine $line): array => [$line->lineKey, $line->qty, $line->unitPrice, $line->isValid],
            $lines,
        );

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
