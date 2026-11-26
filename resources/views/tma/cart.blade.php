{{--
    Mini App cart (CartController::show, plan 45 §3): lines re-priced on every request (Retail) with a
    thumbnail, the roll description, the unit price, a stepper (plain PRG back here), the line total and
    a 44 px remove button. Lines that can't be bought get a danger edge and their fix. Then the subtotal,
    the VAT note, "Checkout" (MainButton) and "Request a quote for this cart" in <details>.
    The cart itself is never named in the markup.
--}}
@extends('layouts.tma')

@php
    $money = fn (?int $tiyin): string => \App\Support\Money::format((int) $tiyin);
@endphp

@section('content')
    @if ($priced === null || $priced->isEmpty())
        <section class="tma-group grid justify-items-center gap-3 py-6 text-center" data-cart-empty>
            <x-tma.icon name="cart" size="size-8" class="text-tg-hint" />
            <p class="text-tg-hint">{{ __('tma.cart.empty') }}</p>
            <a href="{{ route('tma.home') }}" class="tma-btn">{{ __('tma.cart.to_catalog') }}</a>
        </section>
    @else
        <section class="grid gap-2" data-cart-lines>
            @foreach ($priced->lines as $line)
                @php($thumb = $line->product->imageUrl('thumb'))
                <article class="tma-card grid gap-2 {{ $line->isValid ? '' : 'border-l-4 border-tg-danger' }}" data-line="{{ $line->lineKey }}" @unless ($line->isValid) data-invalid="{{ $line->problem }}" @endunless>
                    <div class="flex gap-3">
                        <a href="{{ route('tma.product', ['product' => $line->product->slug]) }}" class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-[10px] bg-white" tabindex="-1" aria-hidden="true">
                            @if ($thumb !== null)
                                <img src="{{ $thumb }}" alt="" width="56" height="56" loading="lazy" class="size-14 object-contain">
                            @else
                                <x-tma.icon :name="\App\Support\Telegram\MiniApp\TmaCards::icon($line->product->category_id)" size="size-6" class="text-tg-hint" />
                            @endif
                        </a>
                        <div class="grid min-w-0 flex-1 content-start gap-0.5">
                            <a href="{{ route('tma.product', ['product' => $line->product->slug]) }}" class="line-clamp-2 font-medium">{{ $line->product->name }}</a>
                            @if ($line->rollLine !== null)
                                <p class="text-xs text-tg-hint">{{ \App\Models\RollType::describe($line->rollLine->labelsPerRoll, $line->rollLine->coreDiameterMm) }}</p>
                                <p class="text-xs text-tg-subtitle" data-roll-count>{{ __('tma.cart.rolls_labels', ['rolls' => $line->qty, 'labels' => \App\Support\NumberFormat::format($line->qty * $line->rollLine->labelsPerRoll)]) }}</p>
                            @endif
                            @if ($line->unitPrice !== null)
                                <p class="text-xs text-tg-subtitle" data-unit-price>{{ __($line->rollLine !== null ? 'tma.product.per_roll' : 'tma.cart.per_unit', ['price' => $money($line->unitPrice)]) }}</p>
                            @endif
                        </div>
                    </div>
                    @unless ($line->isValid)
                        <p class="text-sm text-tg-danger">{{ $line->problem === \App\Support\Cart\PricedLine::PROBLEM_QUANTITY ? __('tma.cart.line_quantity', ['min' => $line->minQty]) : __('tma.cart.line_unavailable') }}</p>
                    @endunless
                    <div class="flex items-center gap-2">
                        @if ($line->problem !== \App\Support\Cart\PricedLine::PROBLEM_UNAVAILABLE)
                            @include('tma.partials.stepper', ['lineKey' => $line->lineKey, 'qty' => $line->qty, 'min' => $line->minQty, 'from' => \App\Support\Telegram\MiniApp\TmaReturn::CART, 'json' => false, 'label' => (string) $line->product->name])
                        @endif
                        @if ($line->total !== null)
                            <span class="ml-auto font-semibold" data-line-total>{{ $money($line->total) }}</span>
                        @endif
                        <form method="POST" action="{{ route('tma.cart.remove', ['lineKey' => $line->lineKey]) }}" class="{{ $line->total === null ? 'ml-auto' : '' }}">
                            @csrf
                            <button type="submit" class="grid size-11 place-items-center rounded-[10px] text-tg-danger" aria-label="{{ __('tma.cart.remove') }}: {{ $line->product->name }}" data-remove><x-tma.icon name="trash" /></button>
                        </form>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="tma-card grid gap-1 text-sm" data-cart-totals>
            <div class="flex justify-between text-base font-semibold"><span>{{ __('tma.cart.subtotal') }}</span><span data-subtotal>{{ $money($priced->subtotal) }}</span></div>
            <p class="text-xs text-tg-hint">{{ __('tma.cart.vat_note') }}</p>
        </section>

        @if ($canCheckout)
            <a href="{{ route('tma.checkout') }}" class="tma-btn" data-main-button="{{ __('tma.cart.checkout') }}" data-checkout-link>{{ __('tma.cart.checkout') }}</a>
        @else
            <p class="tma-card text-sm text-tg-danger" data-cart-blocked>{{ __('tma.cart.fix_lines') }}</p>
        @endif

        <details class="tma-card">
            <summary class="tma-row cursor-pointer text-sm font-medium">{{ __('tma.cart.quote_title') }}</summary>
            <form method="POST" action="{{ route('tma.cart.quote') }}" class="grid gap-2" data-cart-quote>
                @csrf
                <textarea name="comment" rows="2" maxlength="500" class="tma-input" placeholder="{{ __('tma.request.comment_placeholder') }}" aria-label="{{ __('tma.request.comment') }}"></textarea>
                <button type="submit" class="tma-btn-outline">{{ __('tma.cart.quote_submit') }}</button>
                <p class="text-xs text-tg-hint">{{ __('tma.request.note') }}</p>
            </form>
        </details>
    @endif
@endsection
