<?php

namespace App\Livewire\Site\Cart;

use App\Livewire\Site\Cart\Concerns\AddsToCart;
use App\Models\Cart;
use App\Models\Product;
use App\Support\Cart\CartPricer;
use App\Support\Catalog\ProductCtas;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Add to cart" with a quantity stepper in the product page's buy box
 * (plan 11a; label products use the roll picker instead). The product id
 * is locked; the quantity is untyped on purpose (a tampered request may
 * send a string or an array) and normalized like the roll picker's rolls:
 * min_qty..Cart::MAX_QTY, empty / non-numeric → the minimum. Nothing about
 * the cart (id, prices, totals) is kept in the component: AddToCart checks
 * the product again and CartPricer prices it on the cart page.
 */
class AddButton extends Component
{
    use AddsToCart;

    #[Locked]
    public int $productId;

    public mixed $qty = 1;

    /**
     * The last add succeeded ("Added" + link to the cart).
     */
    public bool $added = false;

    private ?Product $loaded = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        $this->qty = $this->minQty();
    }

    public function updatedQty(): void
    {
        $this->normalize();
    }

    public function increment(): void
    {
        $this->qty = $this->qtyOrMinimum() + 1;
        $this->normalize();
    }

    public function decrement(): void
    {
        $this->qty = $this->qtyOrMinimum() - 1;
        $this->normalize();
    }

    public function add(): void
    {
        $this->normalize();
        $product = $this->product();

        $this->added = $this->addProductToCart($this->productId, null, (int) $this->qty, $product?->sku);
    }

    public function render(): View
    {
        $product = $this->product();

        return view('livewire.site.cart.add-button', [
            'available' => $product !== null && config('site.cart_enabled') && ProductCtas::canAddToCart($product),
            'minQty' => $this->minQty(),
            'maxQty' => Cart::MAX_QTY,
            'unit' => $product?->unit->label() ?? '',
        ]);
    }

    /**
     * Cast and clamp the quantity to min_qty..MAX_QTY.
     */
    private function normalize(): void
    {
        $this->added = false;
        $this->qty = max($this->minQty(), min(Cart::MAX_QTY, $this->qtyOrMinimum()));
    }

    private function qtyOrMinimum(): int
    {
        $qty = $this->qty;

        if (is_int($qty)) {
            return $qty;
        }

        if ((is_string($qty) || is_float($qty)) && is_numeric($qty)) {
            return (int) max(-Cart::MAX_QTY, min(Cart::MAX_QTY + 1, (float) $qty));
        }

        return $this->minQty();
    }

    private function minQty(): int
    {
        $product = $this->product();

        return $product !== null ? CartPricer::minQty($product) : 1;
    }

    /**
     * The product while it is on the site (one load per request).
     */
    private function product(): ?Product
    {
        return $this->loaded ??= Product::query()
            ->visible()
            ->find($this->productId, ['id', 'sku', 'pricing_unit', 'unit', 'price', 'sale_mode', 'stock_status', 'min_qty', 'is_active', 'category_id']);
    }
}
