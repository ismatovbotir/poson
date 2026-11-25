{{--
    A product on a Mini App listing ($card: App\Data\Site\ProductCard). The href is built here from the
    slug (never $card->url, a site URL). Retail price only; no CTA buttons on the card (the product page has them).
--}}
<a href="{{ route('tma.product', ['product' => $card->slug]) }}" class="tma-card flex gap-3" data-product-card="{{ $card->slug }}">
    <span class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-lg bg-white">
        @if ($card->image !== null)
            <img src="{{ $card->image }}" alt="{{ $card->imageAlt }}" width="80" height="80" loading="lazy" class="size-20 object-contain">
        @elseif ($card->diagram !== null)
            <x-catalog.product-diagram :diagram="$card->diagram" context="card" class="size-20" />
        @else
            <span class="text-xs text-ink-3">POSON</span>
        @endif
    </span>
    <span class="grid min-w-0 content-start gap-1">
        <span class="line-clamp-2 font-medium">{{ $card->name }}</span>
        @if ($card->brand)
            <span class="text-xs text-tg-hint">{{ $card->brand }} · {{ $card->sku }}</span>
        @else
            <span class="text-xs text-tg-hint">{{ $card->sku }}</span>
        @endif
        <span class="font-semibold" data-price>
            @if ($card->price !== null)
                {{ $card->pricedPerLabel ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($card->price)]) : \App\Support\Money::format($card->price) }}
            @else
                {{ __('site.card.price_on_request') }}
            @endif
        </span>
        @if ($card->showStock)
            <span class="text-xs text-tg-hint">{{ __('site.stock.'.$card->stockStatus->value) }}</span>
        @endif
    </span>
</a>
