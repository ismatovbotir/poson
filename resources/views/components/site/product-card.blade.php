@props(['product'])

{{--
    Product card (App\Data\Site\ProductCard, from App\Support\Catalog\ProductCards).
    Brand rule: Retail price only (or "price on request"), no old price, no sale tags;
    badges are "hit" and "new" only. Buttons come from App\Support\Catalog\ProductCtas
    (compact): "Try" + "Contact us" with a partner link, "Add to cart" while the cart is
    on and allowed, else "Request a quote" ("Contact us" for software), which opens the
    lead form on the product page. Plan 11a: "Add to cart" adds the product at its minimum
    in place (header Site\Cart\Counter); a label product's button leads to its roll picker.
--}}
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
        @if ($product->showStock)
            <x-site.stock-badge :status="$product->stockStatus" />
        @endif

        <div class="mt-auto flex flex-col gap-3 pt-3">
            @if ($product->price !== null)
                {{-- Plan 08: label products show the price of one label ("12 soʻm / label"). --}}
                <p class="text-[17px] leading-[1.1] font-extrabold tracking-[-0.02em] sm:text-xl" data-price>{{ $product->pricedPerLabel ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($product->price)]) : \App\Support\Money::format($product->price) }}</p>
            @else
                <p class="text-[15px] font-semibold text-ink-2" data-price>{{ __('site.card.price_on_request') }}</p>
            @endif

            @if ($product->ctas !== [])
                <div class="flex flex-col gap-2" data-ctas>
                    @foreach ($product->ctas as $cta)
                        <x-site.product-cta :cta="$cta" size="card" :product-name="$product->name" :add-in-place="! $product->pricedPerLabel" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</article>
