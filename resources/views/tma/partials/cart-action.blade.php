{{--
    "Add to cart" that turns into the line stepper once the product is in the cart (plan 45 §3).
    Both are server-rendered; tma.js toggles them after a JSON answer (never re-sends an add).
    Vars: $productId, $lineKey, $qty (in the cart), $min, $from, $name (product name), $compact (card).
--}}
<div class="grid justify-items-end gap-1" data-cart-box>
    <form method="POST" action="{{ route('tma.cart.add') }}" data-cart-form="add" @if ($qty > 0) hidden @endif>
        @csrf
        <input type="hidden" name="product_id" value="{{ $productId }}">
        <input type="hidden" name="qty" value="{{ $min }}">
        @if ($from !== null)
            <input type="hidden" name="from" value="{{ $from }}">
        @endif
        <button type="submit" class="tma-btn min-h-11 w-auto px-3 text-sm" aria-label="{{ __('site.card.add_to_cart') }}: {{ $name }}" data-cta="cart"><x-tma.icon name="plus" size="size-4" /> {{ __('tma.card.add') }}</button>
    </form>
    @include('tma.partials.stepper', ['lineKey' => $lineKey, 'qty' => $qty, 'min' => $min, 'from' => $from, 'json' => true, 'label' => $name])
    @if ($min > 1)
        <p class="text-xs text-tg-hint">{{ __('tma.product.min_qty', ['count' => $min]) }}</p>
    @endif
    <p class="text-right text-xs text-tg-danger" data-cart-error hidden></p>
</div>
