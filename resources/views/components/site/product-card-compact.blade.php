@props(['product'])

{{--
    Compact related-product card (accessories, consumables, alternatives on the product page).
    Same DTO as x-site.product-card (App\Data\Site\ProductCard) but small and quiet: no CTA buttons,
    the whole card is one link. Brand rule: Retail price only (or "price on request"), no old price, no sale tags.
--}}
<article {{ $attributes->class('group relative flex items-center gap-3 rounded-box-lg border border-line bg-white p-2.5 transition-shadow hover:border-ink-soft hover:shadow-card') }} data-product-card-compact data-mode="{{ $product->saleMode->value }}">
    <span class="grid size-[72px] shrink-0 place-items-center overflow-hidden rounded-lg bg-bg-soft">
        @if ($product->image)
            <img src="{{ $product->image }}" alt="" width="72" height="72" loading="lazy" decoding="async" class="size-[84%] object-contain">
        @elseif ($product->diagram)
            <x-catalog.product-diagram :diagram="$product->diagram" context="card" class="size-[84%]" />
        @else
            <svg viewBox="0 0 120 120" class="size-[60%]" aria-hidden="true" focusable="false"><use href="#{{ $product->placeholder }}" /></svg>
        @endif
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <h3 class="line-clamp-2 text-[13px] leading-[1.3] font-semibold">
            <a href="{{ $product->url }}" class="after:absolute after:inset-0 after:rounded-box-lg group-hover:text-brand-600 focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-brand-600">{{ $product->name }}</a>
            @if ($product->badge)
                <span class="ml-1 align-middle text-[10px] font-bold tracking-wide text-ink-2 uppercase" data-badge="{{ $product->badge->value }}">{{ __('site.badges.'.$product->badge->value) }}</span>
            @endif
        </h3>
        @if ($product->specs !== '')
            <span class="truncate text-xs text-ink-2" data-key-specs>{{ $product->specs }}</span>
        @endif
        @if ($product->price !== null)
            <p class="text-sm font-bold" data-price>{{ $product->pricedPerLabel ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($product->price)]) : \App\Support\Money::format($product->price) }}</p>
        @else
            <p class="text-xs font-semibold text-ink-2" data-price>{{ __('site.card.price_on_request') }}</p>
        @endif
    </div>
</article>
