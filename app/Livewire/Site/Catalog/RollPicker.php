<?php

namespace App\Livewire\Site\Catalog;

use App\Actions\Quotes\SaveQuoteItems;
use App\Data\Catalog\RollLine;
use App\Enums\PriceLevel;
use App\Livewire\Site\Cart\Concerns\AddsToCart;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\ProductCtas;
use App\Support\Catalog\RollPricing;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Roll picker of a label product page (plan 08): roll type radios (the
 * product's active roll types in admin order, the first preselected), a
 * rolls stepper from min_qty to MAX_ROLLS, and the live "Per roll" and
 * "Total" at the Retail price (RollPricing). Without a Retail price no
 * amounts are shown (quote only). Every change is sent to the lead form on
 * the page as "roll-selected" (productId, rollTypeId, rolls), which checks it
 * again on submit. The buttons below follow sale_mode (ProductCtas).
 *
 * Plan 11a: while the cart is on and the product can go to the cart
 * (ProductCtas::canAddToCart()), "Add to cart" adds the chosen roll type
 * and rolls (addToCart(); AddToCart prices nothing from here and checks
 * the roll type and rolls again).
 *
 * Brand rule: Retail only, no discount wording.
 */
class RollPicker extends Component
{
    use AddsToCart;

    /**
     * Most rolls in one request (a quote line's maximum).
     */
    public const MAX_ROLLS = SaveQuoteItems::MAX_QTY;

    #[Locked]
    public int $productId;

    /**
     * The chosen roll type id. Untyped on purpose: a tampered request may send
     * any value (a string, an array), which normalize() casts back to an
     * offered roll type instead of failing with a TypeError.
     */
    public mixed $rollTypeId = null;

    /**
     * Rolls; null, an emptied input or a non-numeric value counts as the
     * minimum. Untyped for the same reason as $rollTypeId (cast in normalize()).
     */
    public mixed $rolls = 1;

    /**
     * The last "Add to cart" succeeded ("Added" + link to the cart).
     */
    public bool $added = false;

    private ?Product $loaded = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        $product = $this->product();

        abort_unless($product !== null && $product->isPricedPerLabel(), 404);

        $this->rollTypeId = $product->activeRollTypes->first()?->id;
        $this->rolls = $this->minRolls();
    }

    public function updatedRollTypeId(): void
    {
        $this->normalize();
    }

    public function updatedRolls(): void
    {
        $this->normalize();
    }

    public function increment(): void
    {
        $this->rolls = $this->rollsOrMinimum() + 1;
        $this->normalize();
    }

    public function decrement(): void
    {
        $this->rolls = $this->rollsOrMinimum() - 1;
        $this->normalize();
    }

    /**
     * Add the chosen roll type and rolls to the cart (plan 11a).
     */
    public function addToCart(): void
    {
        $this->normalize();
        $product = $this->product();
        $rollTypeId = is_int($this->rollTypeId) ? $this->rollTypeId : null;

        if ($product === null || $rollTypeId === null) {
            $this->addError('cart', __('checkout.cart.errors.unavailable'));

            return;
        }

        $this->added = $this->addProductToCart($product->id, $rollTypeId, (int) $this->rolls, $product->sku);
    }

    public function render(): View
    {
        $product = $this->product();
        $rollTypes = $product?->activeRollTypes ?? collect();

        return view('livewire.site.catalog.roll-picker', [
            'product' => $product,
            'rollTypes' => $rollTypes,
            'canAddToCart' => $product !== null && config('site.cart_enabled') && ProductCtas::canAddToCart($product),
            'minRolls' => $this->minRolls(),
            'maxRolls' => self::MAX_ROLLS,
            'line' => $this->line(),
        ]);
    }

    /**
     * Cast and clamp to an offered roll type and min_qty..MAX_ROLLS, then tell the lead form.
     */
    private function normalize(): void
    {
        $this->added = false;
        $rollTypes = $this->product()?->activeRollTypes ?? collect();
        $rollTypeId = filter_var($this->rollTypeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->rollTypeId = $rollTypeId !== false && $rollTypes->contains('id', $rollTypeId)
            ? $rollTypeId
            : $rollTypes->first()?->id;

        $this->rolls = max($this->minRolls(), min(self::MAX_ROLLS, $this->rollsOrMinimum()));

        if ($this->rollTypeId !== null) {
            $this->dispatch('roll-selected', productId: $this->productId, rollTypeId: $this->rollTypeId, rolls: $this->rolls);
        }
    }

    /**
     * The rolls as an integer: the minimum when empty or not a number.
     */
    private function rollsOrMinimum(): int
    {
        $rolls = $this->rolls;

        if (is_int($rolls)) {
            return $rolls;
        }

        if ((is_string($rolls) || is_float($rolls)) && is_numeric($rolls)) {
            return (int) max(-self::MAX_ROLLS, min(self::MAX_ROLLS + 1, (float) $rolls));
        }

        return $this->minRolls();
    }

    private function minRolls(): int
    {
        return max(1, min(self::MAX_ROLLS, (int) $this->product()?->min_qty));
    }

    /**
     * The line at the Retail price; null without an offered roll type.
     */
    private function line(): ?RollLine
    {
        $product = $this->product();
        $rollType = $product?->activeRollTypes->firstWhere('id', $this->rollTypeId);

        if (! $rollType instanceof RollType) {
            return null;
        }

        try {
            // Retail only (brand rule): the site never reads Dealer / Wholesale.
            return RollPricing::line($product, $rollType, max(1, $this->rollsOrMinimum()), $product->price, PriceLevel::Retail);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * The product while it is on the site, with its active roll types (one load per request).
     */
    private function product(): ?Product
    {
        if ($this->loaded === null) {
            $this->loaded = Product::query()
                ->visible()
                ->with('activeRollTypes')
                ->find($this->productId, ['id', 'sku', 'pricing_unit', 'price', 'sale_mode', 'stock_status', 'min_qty', 'is_active', 'category_id']);

            // RollPricing reads "rollTypes" when loaded: the active ones are all it may offer.
            $this->loaded?->setRelation('rollTypes', $this->loaded->activeRollTypes);
        }

        return $this->loaded;
    }
}
