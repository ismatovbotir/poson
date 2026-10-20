@props(['product'])

{{--
    Product card (App\Data\Site\ProductCard).
    Brand rule: retail price only (or "price on request"), no old price, no sale tags;
    badges are "hit" and "new" only. CTA follows sale_mode: cart/both with a price
    shows "Add to cart" (links to the product until the cart exists), otherwise
    "Request a quote" (the lead form).
--}}
@php
    $stockClass = match ($product->stockStatus) {
        \App\Enums\StockStatus::InStock => 'text-ok',
        \App\Enums\StockStatus::OnOrder => 'text-warn',
        \App\Enums\StockStatus::OutOfStock => 'text-ink-2',
    };
@endphp

<article {{ $attributes->class('relative flex flex-col overflow-hidden rounded-box-lg border border-line bg-white transition-shadow hover:border-ink-soft hover:shadow-card') }} data-product-card data-mode="{{ $product->saleMode->value }}">
    <div class="relative grid aspect-[1/0.85] place-items-center bg-bg-soft">
        @if ($product->badge)
            <span @class([
                'absolute top-3 left-3 rounded-md px-2.5 py-0.5 text-xs font-bold',
                'bg-warn text-ink' => $product->badge === \App\Enums\ProductBadge::Hit,
                'bg-brand-600 text-white' => $product->badge === \App\Enums\ProductBadge::New,
            ]) data-badge="{{ $product->badge->value }}">{{ __('site.badges.'.$product->badge->value) }}</span>
        @endif
        <img src="{{ $product->image }}" alt="{{ $product->name }}" width="120" height="120" loading="lazy" decoding="async" class="size-[58%] object-contain">
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-3 sm:px-[18px] sm:pt-4 sm:pb-[18px]">
        @if ($product->category)
            <span class="text-xs font-semibold tracking-[0.05em] text-ink-2 uppercase">{{ $product->category }}</span>
        @endif
        <h3 class="text-sm leading-[1.35] font-semibold sm:text-[15px]">
            <a href="{{ $product->url }}" class="hover:text-brand-600">{{ $product->name }}</a>
        </h3>
        <span class="text-[13px] text-ink-2">{{ $product->specs }}</span>
        <span class="inline-flex items-center gap-1.5 text-[13px] font-medium {{ $stockClass }}">
            <span class="size-[7px] rounded-full bg-current" aria-hidden="true"></span>
            {{ __('site.stock.'.$product->stockStatus->value) }}
        </span>

        <div class="mt-auto flex flex-col gap-3 pt-3">
            @if ($product->price !== null)
                <p class="text-[17px] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-xl" data-price>{{ \App\Support\Money::format($product->price) }}</p>
            @else
                <p class="text-[15px] font-semibold text-ink-2" data-price>{{ __('site.card.price_on_request') }}</p>
            @endif

            @if ($product->canAddToCart())
                {{-- The cart doesn't exist yet: a muted button that opens the product. --}}
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
                    href="{{ \App\Support\Site\Navigation::quote() }}"
                    class="inline-flex h-11 items-center justify-center rounded-btn bg-brand-600 px-3 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                    data-cta="quote"
                >
                    {{ __('site.card.request_quote') }}
                </a>
            @endif
        </div>
    </div>
</article>
