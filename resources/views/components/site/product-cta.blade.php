@props(['cta', 'size' => 'card', 'href' => null, 'productName' => null])

{{--
    One product CTA (App\Data\Site\ProductCta from App\Support\Catalog\ProductCtas).
    size: card (product cards), page (buy box), bar (mobile sticky bar).
    href: defaults to the CTA's url; the product page passes $cta->pageHref().
    "Try" opens the tracked partner redirect in a new tab with rel="nofollow sponsored noopener".
--}}
@php
    $href ??= $cta->url;
    $external = $cta->isExternal();
    $isCartOnCard = $size === 'card' && $cta->kind === \App\Enums\ProductCtaKind::Cart;
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-btn font-semibold transition-colors',
        'h-11 px-3 text-sm' => $size === 'card',
        'h-[54px] flex-1 px-[30px] text-base' => $size === 'page',
        'h-12 px-5 text-sm' => $size === 'bar',
        // Cards keep the template's neutral cart button.
        'border-[1.5px] border-line bg-bg-soft text-ink-2 hover:border-ink-soft' => $isCartOnCard,
        'bg-brand-600 text-white hover:bg-brand-700' => $cta->primary && ! $isCartOnCard,
        'border-[1.5px] border-line bg-white text-ink hover:border-brand hover:text-brand-600' => ! $cta->primary,
    ];
@endphp

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
