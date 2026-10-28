@props(['cta', 'size' => 'card', 'href' => null, 'productName' => null, 'addInPlace' => false])

{{--
    One product CTA (App\Data\Site\ProductCta from App\Support\Catalog\ProductCtas).
    size: card (product cards), page (buy box), bar (mobile sticky bar).
    href: defaults to the CTA's url; the product page passes $cta->pageHref().
    "Try" opens the tracked partner redirect in a new tab with rel="nofollow sponsored noopener".
    addInPlace (plan 11a, product cards): "Add to cart" is a button that sends "cart-add"
    with the product id to the header Site\Cart\Counter instead of a link (label products
    keep the link: their roll is picked on the product page).
--}}
@php
    $href ??= $cta->url;
    $external = $cta->isExternal();
    $isCartOnCard = $size === 'card' && $cta->kind === \App\Enums\ProductCtaKind::Cart;
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-btn font-semibold transition-colors',
        'h-11 px-3 text-center text-sm leading-tight' => $size === 'card',
        'h-[54px] flex-1 px-[30px] text-base' => $size === 'page',
        'h-12 px-5 text-sm' => $size === 'bar',
        // Cards keep the template's neutral cart button.
        'border-[1.5px] border-line bg-bg-soft text-ink-2 hover:border-ink-soft' => $isCartOnCard,
        'bg-brand-600 text-white hover:bg-brand-700' => $cta->primary && ! $isCartOnCard,
        'border-[1.5px] border-line bg-white text-ink hover:border-brand hover:text-brand-600' => ! $cta->primary,
    ];
@endphp

@if ($addInPlace && $cta->isCart())
<button
    type="button"
    x-data="{ busy: false, timer: null }"
    x-on:click="if (busy) return; busy = true; clearTimeout(timer); timer = setTimeout(() => busy = false, 8000); Livewire.dispatch('cart-add', { productId: {{ (int) $cta->productId }} })"
    x-on:cart-notice.window="busy = false; clearTimeout(timer)"
    x-bind:disabled="busy"
    x-bind:aria-busy="busy.toString()"
    {{ $attributes->class([...$classes, 'disabled:cursor-wait disabled:opacity-70']) }}
    data-cta="{{ $cta->kind->value }}"
    data-cart-add
>
    <x-site.icon name="cart" />
    {{ __($cta->labelKey) }}@if ($productName)<span class="sr-only">: {{ $productName }}</span>@endif
</button>
@else
<a
    href="{{ $href }}"
    @if ($external) target="_blank" rel="nofollow sponsored noopener" @endif
    {{ $attributes->class($classes) }}
    data-cta="{{ $cta->kind->value }}"
>
    @if ($cta->kind === \App\Enums\ProductCtaKind::Cart)
        <x-site.icon name="cart" />
    @endif
    {{ __($cta->labelKey) }}@if ($productName)<span class="sr-only">: {{ $productName }}</span>@endif
    @if ($external)
        <x-site.icon name="arrow" size="size-4" />
        <span class="sr-only">({{ __('site.product.new_tab') }})</span>
    @endif
</a>
@endif
