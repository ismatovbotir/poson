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
    <section class="tma-card grid gap-4" data-product="{{ $product->slug }}">
        @if ($images !== [])
            <div class="-mx-3 flex snap-x snap-mandatory gap-2 overflow-x-auto px-3" data-gallery>
                @foreach ($images as $image)
                    <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" width="600" height="600" @if ($image['large']) fetchpriority="high" @else loading="lazy" @endif class="aspect-square w-full shrink-0 snap-center rounded-[10px] bg-white object-contain">
                @endforeach
            </div>
            @if (count($images) > 1)
                <p class="-mt-2 text-center text-xs text-tg-hint" data-gallery-count>{{ __('tma.product.photos', ['count' => count($images)]) }}</p>
            @endif
        @elseif ($diagram !== null)
            <div class="rounded-[10px] bg-white p-3" data-diagram>
                <x-catalog.product-diagram :diagram="$diagram" context="page" class="mx-auto w-full max-w-xs" />
            </div>
        @else
            <div class="grid aspect-[2/1] place-items-center rounded-[10px] bg-white"><x-tma.icon :name="$categoryIcon" size="size-12" class="text-tg-hint" /></div>
        @endif

        <div class="grid gap-1">
            <h1 class="text-xl leading-snug font-semibold">{{ $product->name }}</h1>
            <p class="text-xs text-tg-hint">
                @if ($brand){{ $brand->name }} · @endif{{ __('tma.product.sku', ['sku' => $product->sku]) }}
            </p>
            <p class="text-[22px] font-bold" data-price>
                @if ($product->price !== null)
                    {{ $pricedPerLabel ? __('catalog.roll.per_label', ['price' => $money($product->price)]) : $money($product->price) }}
                @else
                    {{ __('site.card.price_on_request') }}
                @endif
            </p>
            @if ($product->showsStockStatus())
                <p class="text-sm text-tg-subtitle" data-stock>{{ __('site.stock.'.$product->stock_status->value) }}</p>
            @endif
        </div>

        <ul class="grid gap-1.5 rounded-[10px] bg-tg-secondary p-3 text-sm" data-service-strip>
            @foreach (['warranty' => 'shield', 'service' => 'wrench', 'setup' => 'cat-setup-integration'] as $point => $icon)
                <li class="flex items-center gap-2"><x-tma.icon :name="$icon" size="size-[18px]" class="text-tg-accent" /> {{ __('tma.product.assurance.'.$point) }}</li>
            @endforeach
        </ul>

        @if ($canAddToCart)
            <div class="grid gap-3" id="buy" data-cart-box>
                <form method="POST" action="{{ route('tma.cart.add') }}" class="grid gap-3" data-cart-form="add" @if ($inCartQty > 0) hidden @endif>
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">

                    @if ($pricedPerLabel)
                        <fieldset class="grid gap-2" data-roll-picker>
                            <legend class="mb-1 text-sm font-semibold">{{ __('site.roll_picker.title') }}</legend>
                            @foreach ($rollTypes as $rollType)
                                <label class="tma-row rounded-[10px] border border-tg-separator px-3 text-sm has-[:checked]:border-tg-button">
                                    <input type="radio" name="roll_type_id" value="{{ $rollType['id'] }}" @checked($loop->first) required class="size-5 shrink-0 accent-tg-button" data-roll-type-option="{{ $rollType['id'] }}">
                                    <span class="flex-1 font-medium">{{ $rollType['label'] }}</span>
                                    @if ($rollType['rollPrice'] !== null)
                                        <span class="text-tg-hint" data-roll-price>{{ __('tma.product.per_roll', ['price' => $money($rollType['rollPrice'])]) }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <label for="tma-qty" class="font-medium">{{ $pricedPerLabel ? __('site.roll_picker.rolls') : __('tma.product.quantity') }}</label>
                        <span class="tma-stepper">
                            <button type="button" data-qty-step="-1" aria-label="{{ __('tma.stepper.less') }}"><x-tma.icon name="minus" /></button>
                            <input id="tma-qty" type="number" name="qty" value="{{ $minQty }}" min="{{ $minQty }}" max="{{ \App\Models\Cart::MAX_QTY }}" step="1" inputmode="numeric" required class="h-11 w-16 bg-transparent">
                            <button type="button" data-qty-step="1" aria-label="{{ __('tma.stepper.more') }}"><x-tma.icon name="plus" /></button>
                        </span>
                        @if ($minQty > 1)
                            <span class="w-full text-xs text-tg-hint">{{ __('tma.product.min_qty', ['count' => $minQty]) }}</span>
                        @endif
                    </div>

                    <button type="submit" class="tma-btn" data-main-button="{{ __('site.card.add_to_cart') }}" data-cta="cart">{{ __('site.card.add_to_cart') }}</button>
                </form>

                @if ($lineKey !== null)
                    <div class="flex items-center justify-between gap-3" data-in-cart @if ($inCartQty < 1) hidden @endif>
                        <span class="text-sm font-medium">{{ __('tma.product.in_cart') }}</span>
                        @include('tma.partials.stepper', ['lineKey' => $lineKey, 'qty' => $inCartQty, 'min' => $minQty, 'from' => \App\Support\Telegram\MiniApp\TmaReturn::PRODUCT, 'json' => true, 'label' => (string) $product->name])
                    </div>
                @endif
                @if ($pricedPerLabel)
                    <p class="flex flex-wrap items-center justify-between gap-2 text-sm" data-roll-in-cart @if ($rollInCartQty < 1) hidden @endif>
                        <span>{{ __('tma.product.rolls_in_cart') }} <span class="font-semibold" data-roll-qty>{{ $rollInCartQty }}</span></span>
                        <a href="{{ route('tma.cart') }}" class="inline-flex min-h-11 items-center font-medium text-tg-link">{{ __('tma.product.open_cart') }}</a>
                    </p>
                @endif
                <p class="text-sm text-tg-danger" data-cart-error hidden></p>
            </div>
        @endif

        @if ($tryUrl !== null)
            <a href="{{ $tryUrl }}" data-open-link="{{ $tryUrl }}" target="_blank" rel="noopener noreferrer" class="tma-btn" data-cta="try">{{ __('site.cta.try') }}</a>
        @endif

        @if ($inquiry !== null)
            @php($inquiryIsMain = ! ($mainIsCart || $tryUrl !== null))
            <form method="POST" action="{{ route('tma.quote', ['product' => $product->slug]) }}" class="grid gap-2" id="request" data-request-form="{{ $inquiry->kind->value }}">
                @csrf
                <details class="text-sm" @if ($inquiryIsMain) open @endif>
                    <summary class="tma-row cursor-pointer font-medium text-tg-link">{{ __('tma.request.comment') }}</summary>
                    <textarea name="comment" rows="2" maxlength="500" class="tma-input" placeholder="{{ __('tma.request.comment_placeholder') }}" aria-label="{{ __('tma.request.comment') }}"></textarea>
                </details>
                <button type="submit" class="{{ $inquiryIsMain ? 'tma-btn' : 'tma-btn-outline' }}" @if ($inquiryIsMain) data-main-button="{{ $inquiry->label() }}" @endif data-cta="{{ $inquiry->kind->value }}">{{ $inquiry->label() }}</button>
                <p class="text-xs text-tg-hint">{{ __('tma.request.note') }}</p>
            </form>
        @endif

        @if ($deviceService)
            <a href="{{ route('tma.service', ['product' => $product->slug]) }}" class="tma-row text-sm font-medium text-tg-link underline" data-cta="device_service">{{ __('site.cta.device_service') }}</a>
        @endif
    </section>

    @php($specRows = $specs !== [] ? $specs : $keySpecs)
    @if ($specRows !== [])
        <section class="tma-card" aria-labelledby="tma-specs">
            <h2 id="tma-specs" class="mb-1 font-semibold">{{ __('tma.product.specs') }}</h2>
            <dl class="grid text-sm" data-specs>
                @foreach (array_slice($specRows, 0, 6) as $row)
                    <div class="flex justify-between gap-3 border-b border-tg-separator py-2 last:border-0">
                        <dt class="text-tg-hint">{{ $row['label'] }}</dt>
                        <dd class="text-right font-medium">{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
            @if (count($specRows) > 6)
                <details data-specs-more>
                    <summary class="tma-row cursor-pointer text-sm font-medium text-tg-link">{{ __('tma.product.all_specs', ['count' => count($specRows)]) }}</summary>
                    <dl class="grid text-sm">
                        @foreach (array_slice($specRows, 6) as $row)
                            <div class="flex justify-between gap-3 border-b border-tg-separator py-2 last:border-0">
                                <dt class="text-tg-hint">{{ $row['label'] }}</dt>
                                <dd class="text-right font-medium">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endif
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
