{{--
    A product on a Mini App listing ($item: App\Data\Telegram\TmaProductCard, $from: TmaReturn of the
    screen or null). An <article> with a title link, so the action can sit inside it (plan 45 §2-3).
    Hrefs are built here from the slug (never $card->url, a site URL). Retail price only, no badge on a
    price, no old price (brand rule). The action by kind: add / stepper, "Choose roll", "Request a quote".
--}}
@php
    $card = $item->card;
    $url = route('tma.product', ['product' => $card->slug]);
    $dot = match ($card->stockStatus) {
        \App\Enums\StockStatus::InStock => 'bg-ok',
        \App\Enums\StockStatus::OnOrder => 'bg-amber-500',
        default => 'bg-tg-hint',
    };
@endphp
<article id="p-{{ $item->id }}" class="tma-card flex gap-3" data-product-card="{{ $card->slug }}">
    <a href="{{ $url }}" class="grid size-24 shrink-0 place-items-center overflow-hidden rounded-[10px] bg-white" tabindex="-1" aria-hidden="true">
        @if ($card->image !== null)
            <img src="{{ $card->image }}" alt="" width="96" height="96" loading="lazy" class="size-24 object-contain">
        @elseif ($card->diagram !== null)
            <x-catalog.product-diagram :diagram="$card->diagram" context="card" class="size-24" />
        @else
            <x-tma.icon :name="$item->icon" size="size-8" class="text-tg-hint" />
        @endif
    </a>
    <div class="grid min-w-0 flex-1 content-start gap-1">
        <a href="{{ $url }}" class="line-clamp-2 text-[17px] leading-snug font-medium">{{ $card->name }}</a>
        @if ($card->specs !== '')
            <p class="truncate text-xs text-tg-subtitle" data-card-specs>{{ $card->specs }}</p>
        @endif
        <p class="truncate text-xs text-tg-hint">@if ($card->brand){{ $card->brand }} · @endif{{ $card->sku }}</p>
        @if ($card->showStock)
            <p class="flex items-center gap-1.5 text-xs text-tg-subtitle" data-stock><span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ __('site.stock.'.$card->stockStatus->value) }}</p>
        @endif
        <div class="mt-1 flex flex-wrap items-end justify-between gap-2">
            <p class="font-semibold" data-price>
                @if ($card->price !== null)
                    {{ $card->pricedPerLabel ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($card->price)]) : \App\Support\Money::format($card->price) }}
                @else
                    {{ __('site.card.price_on_request') }}
                @endif
            </p>
            @if ($item->cartKind === \App\Data\Telegram\TmaProductCard::KIND_CART)
                @include('tma.partials.cart-action', ['productId' => $item->id, 'lineKey' => $item->lineKey, 'qty' => $item->inCartQty, 'min' => $item->minQty, 'from' => $from, 'name' => $card->name])
            @elseif ($item->cartKind === \App\Data\Telegram\TmaProductCard::KIND_ROLL)
                <a href="{{ $url }}#buy" class="tma-btn-outline min-h-11 w-auto px-3 text-sm" data-cta="roll">{{ __('tma.card.choose_roll') }}</a>
            @else
                <a href="{{ $url }}#request" class="tma-btn-outline min-h-11 w-auto px-3 text-sm" data-cta="quote">{{ $item->actionLabel }}</a>
            @endif
        </div>
    </div>
</article>
