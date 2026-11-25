{{--
    Mini App product page (ProductController, plan 20 §6.3). Retail price only. Buttons by
    ProductCtaKind: cart (quantity or roll radios), one-tap request (quote / contact / service),
    device service form, partner "Try" (openLink). Documents and videos open outside Telegram.
--}}
@extends('layouts.tma')

@php
    $money = fn (?int $tiyin): string => \App\Support\Money::format((int) $tiyin);
    $mainIsCart = $canAddToCart;
@endphp

@section('content')
    <section class="tma-card grid gap-3" data-product="{{ $product->slug }}">
        @if ($images !== [])
            <div class="grid gap-2" data-gallery>
                @foreach ($images as $image)
                    @if ($image['large'])
                        <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" width="600" height="600" class="aspect-square w-full rounded-lg bg-white object-contain" fetchpriority="high">
                    @endif
                @endforeach
                @if (count($images) > 1)
                    <div class="flex gap-2 overflow-x-auto">
                        @foreach ($images as $image)
                            @unless ($image['large'])
                                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" width="96" height="96" loading="lazy" class="size-24 shrink-0 rounded-lg bg-white object-contain">
                            @endunless
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif ($diagram !== null)
            <div class="rounded-lg bg-white p-3" data-diagram>
                <x-catalog.product-diagram :diagram="$diagram" context="page" class="mx-auto w-full max-w-xs" />
            </div>
        @endif

        <div class="grid gap-1">
            <h1 class="text-lg font-semibold leading-snug">{{ $product->name }}</h1>
            <p class="text-xs text-tg-hint">
                @if ($brand){{ $brand->name }} · @endif{{ __('tma.product.sku', ['sku' => $product->sku]) }}
            </p>
            <p class="text-xl font-bold" data-price>
                @if ($product->price !== null)
                    {{ $pricedPerLabel ? __('catalog.roll.per_label', ['price' => $money($product->price)]) : $money($product->price) }}
                @else
                    {{ __('site.card.price_on_request') }}
                @endif
            </p>
            @if ($product->showsStockStatus())
                <p class="text-sm text-tg-hint" data-stock>{{ __('site.stock.'.$product->stock_status->value) }}</p>
            @endif
        </div>

        @if ($canAddToCart)
            <form method="POST" action="{{ route('tma.cart.add') }}" class="grid gap-3" data-cart-form>
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">

                @if ($pricedPerLabel)
                    <fieldset class="grid gap-2" data-roll-picker>
                        <legend class="mb-1 text-sm font-semibold">{{ __('site.roll_picker.title') }}</legend>
                        @foreach ($rollTypes as $rollType)
                            <label class="flex items-center gap-3 rounded-lg border border-tg-separator px-3 py-2.5 text-sm">
                                <input type="radio" name="roll_type_id" value="{{ $rollType['id'] }}" @checked($loop->first) required class="size-4" data-roll-type-option="{{ $rollType['id'] }}">
                                <span class="flex-1 font-medium">{{ $rollType['label'] }}</span>
                                @if ($rollType['rollPrice'] !== null)
                                    <span class="text-tg-hint" data-roll-price>{{ __('tma.product.per_roll', ['price' => $money($rollType['rollPrice'])]) }}</span>
                                @endif
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ $pricedPerLabel ? __('site.roll_picker.rolls') : __('tma.product.quantity') }}</span>
                    <input type="number" name="qty" value="{{ $minQty }}" min="{{ $minQty }}" max="{{ \App\Models\Cart::MAX_QTY }}" step="1" inputmode="numeric" required class="tma-input w-32">
                    @if ($minQty > 1)
                        <span class="text-xs text-tg-hint">{{ __('tma.product.min_qty', ['count' => $minQty]) }}</span>
                    @endif
                </label>

                <button type="submit" class="tma-btn" data-main-button="{{ __('site.card.add_to_cart') }}" data-cta="cart">{{ __('site.card.add_to_cart') }}</button>
            </form>
        @endif

        @if ($tryUrl !== null)
            <a href="{{ $tryUrl }}" data-open-link="{{ $tryUrl }}" target="_blank" rel="noopener noreferrer" class="tma-btn" data-cta="try">{{ __('site.cta.try') }}</a>
        @endif

        @if ($inquiry !== null)
            <form method="POST" action="{{ route('tma.quote', ['product' => $product->slug]) }}" class="grid gap-2" data-request-form="{{ $inquiry->kind->value }}">
                @csrf
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('tma.request.comment') }}</span>
                    <textarea name="comment" rows="2" maxlength="500" class="tma-input" placeholder="{{ __('tma.request.comment_placeholder') }}"></textarea>
                </label>
                <button type="submit" class="{{ $mainIsCart || $tryUrl !== null ? 'tma-btn-outline' : 'tma-btn' }}" @unless ($mainIsCart || $tryUrl !== null) data-main-button="{{ $inquiry->label() }}" @endunless data-cta="{{ $inquiry->kind->value }}">{{ $inquiry->label() }}</button>
                <p class="text-xs text-tg-hint">{{ __('tma.request.note') }}</p>
            </form>
        @endif

        @if ($deviceService)
            <a href="{{ route('tma.service', ['product' => $product->slug]) }}" class="text-sm font-medium text-tg-link underline" data-cta="device_service">{{ __('site.cta.device_service') }}</a>
        @endif
    </section>

    @if ($keySpecs !== [] || $specs !== [])
        <section class="tma-card" aria-labelledby="tma-specs">
            <h2 id="tma-specs" class="mb-2 font-semibold">{{ __('tma.product.specs') }}</h2>
            <dl class="grid gap-1.5 text-sm" data-specs>
                @foreach ($specs !== [] ? $specs : $keySpecs as $row)
                    <div class="flex justify-between gap-3 border-b border-tg-separator pb-1.5 last:border-0">
                        <dt class="text-tg-hint">{{ $row['label'] }}</dt>
                        <dd class="text-right font-medium">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($software !== [] && $software['chips'] !== [])
        <section class="tma-card grid gap-2 text-sm" data-software-details>
            @foreach ($software['chips'] as $group)
                <div>
                    <p class="text-tg-hint">{{ $group['label'] }}</p>
                    <p class="font-medium">{{ implode(', ', $group['values']) }}</p>
                </div>
            @endforeach
        </section>
    @endif

    @if (trim($description) !== '')
        <section class="tma-card tma-prose text-sm" aria-label="{{ __('tma.product.description') }}" data-description>
            {!! $description !!}
        </section>
    @endif

    @if ($documents !== [] || $videos !== [])
        <section class="tma-card grid gap-2 text-sm" aria-labelledby="tma-files">
            <h2 id="tma-files" class="font-semibold">{{ __('tma.product.files') }}</h2>
            @foreach ($documents as $document)
                <a href="{{ $document['url'] }}" data-open-link="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-2 text-tg-link" data-document>
                    <span>{{ $document['title'] }}</span>
                    <span class="text-xs text-tg-hint">{{ $document['type'] }}@if ($document['locale']) · {{ $document['locale'] }}@endif</span>
                </a>
            @endforeach
            @foreach ($videos as $video)
                <a href="{{ $video['url'] }}" data-open-link="{{ $video['url'] }}" target="_blank" rel="noopener noreferrer" class="text-tg-link" data-video>▶ {{ $video['title'] }}</a>
            @endforeach
        </section>
    @endif

    @if ($botLink !== null)
        <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-card text-center text-sm font-medium text-tg-link" data-contact-manager>{{ __('tma.contact_manager') }}</a>
    @endif
@endsection
