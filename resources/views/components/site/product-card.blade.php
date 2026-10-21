@props(['product'])

{{--
    Product card (App\Data\Site\ProductCard, from App\Support\Catalog\ProductCards).
    Brand rule: Retail price only (or "price on request"), no old price, no sale tags;
    badges are "hit" and "new" only. CTA: "Add to cart" only while
    config('site.cart_enabled') is on and the product allows the cart (cart / both
    with a price); otherwise "Request a quote", which opens the quote form on the
    product page.
--}}
@php
    $showCart = config('site.cart_enabled') && $product->canAddToCart();
    $quoteUrl = $product->url !== '#' ? $product->url.'#quote' : \App\Support\Site\Navigation::quote();
@endphp

<article {{ $attributes->class('relative flex flex-col overflow-hidden rounded-box-lg border border-line bg-white transition-shadow hover:border-ink-soft hover:shadow-card') }} data-product-card data-mode="{{ $product->saleMode->value }}">
    <a href="{{ $product->url }}" class="relative grid aspect-[1/0.85] place-items-center bg-bg-soft" tabindex="-1" aria-hidden="true">
        @if ($product->badge)
            <span @class([
                'absolute top-3 left-3 rounded-md px-2.5 py-0.5 text-xs font-bold',
                'bg-warn text-ink' => $product->badge === \App\Enums\ProductBadge::Hit,
                'bg-brand-600 text-white' => $product->badge === \App\Enums\ProductBadge::New,
            ]) data-badge="{{ $product->badge->value }}">{{ __('site.badges.'.$product->badge->value) }}</span>
        @endif
        @if ($product->image)
            <img src="{{ $product->image }}" alt="{{ $product->imageAlt ?? $product->name }}" width="400" height="400" loading="lazy" decoding="async" class="size-[78%] object-contain">
        @else
            <svg viewBox="0 0 120 120" class="size-[58%]" aria-hidden="true" focusable="false"><use href="#{{ $product->placeholder }}" /></svg>
        @endif
    </a>

    <div class="flex flex-1 flex-col gap-1.5 p-3 sm:px-[18px] sm:pt-4 sm:pb-[18px]">
        @if ($product->category)
            <span class="text-xs font-semibold tracking-[0.05em] text-ink-2 uppercase">{{ $product->category }}</span>
        @endif
        <h3 class="text-sm leading-[1.35] font-semibold sm:text-[15px]">
            <a href="{{ $product->url }}" class="hover:text-brand-600">{{ $product->name }}</a>
        </h3>
        @if ($product->specs !== '')
            <span class="text-[13px] text-ink-2" data-key-specs>{{ $product->specs }}</span>
        @endif
        <x-site.stock-badge :status="$product->stockStatus" />

        <div class="mt-auto flex flex-col gap-3 pt-3">
            @if ($product->price !== null)
                <p class="text-[17px] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-xl" data-price>{{ \App\Support\Money::format($product->price) }}</p>
            @else
                <p class="text-[15px] font-semibold text-ink-2" data-price>{{ __('site.card.price_on_request') }}</p>
            @endif

            @if ($showCart)
                {{-- The cart doesn't exist yet: the button opens the product. --}}
                <a
                    href="{{ $product->url }}"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-btn border-[1.5px] border-line bg-bg-soft px-3 text-sm font-semibold text-ink-2 transition-colors hover:border-ink-soft"
                    data-cta="cart"
                >
                    <x-site.icon name="cart" />
                    {{ __('site.card.add_to_cart') }}
                </a>
            @else
                <a
                    href="{{ $quoteUrl }}"
                    class="inline-flex h-11 items-center justify-center rounded-btn bg-brand-600 px-3 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                    data-cta="quote"
                >
                    {{ __('site.card.request_quote') }}<span class="sr-only">: {{ $product->name }}</span>
                </a>
            @endif
        </div>
    </div>
</article>
