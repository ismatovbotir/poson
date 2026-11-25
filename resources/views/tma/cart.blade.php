{{--
    Mini App cart (CartController::show): lines re-priced on every request (Retail), lines that can't be
    bought are flagged (remove or change them), quantity forms per line key, "Checkout" (MainButton)
    and "Request a quote for this cart". The cart itself is never named in the markup.
--}}
@extends('layouts.tma')

@php
    $money = fn (?int $tiyin): string => \App\Support\Money::format((int) $tiyin);
@endphp

@section('content')
    @if ($priced === null || $priced->isEmpty())
        <section class="tma-card grid gap-3 text-center" data-cart-empty>
            <p class="text-tg-hint">{{ __('tma.cart.empty') }}</p>
            <a href="{{ route('tma.home') }}" class="tma-btn">{{ __('tma.cart.to_catalog') }}</a>
        </section>
    @else
        <section class="grid gap-2" data-cart-lines>
            @foreach ($priced->lines as $line)
                <article class="tma-card grid gap-2" data-line="{{ $line->lineKey }}" @unless ($line->isValid) data-invalid="{{ $line->problem }}" @endunless>
                    <div class="flex justify-between gap-3">
                        <a href="{{ route('tma.product', ['product' => $line->product->slug]) }}" class="font-medium">{{ $line->product->name }}</a>
                        @if ($line->total !== null)
                            <span class="shrink-0 font-semibold" data-line-total>{{ $money($line->total) }}</span>
                        @endif
                    </div>
                    @if ($line->rollLine !== null)
                        <p class="text-xs text-tg-hint">{{ \App\Models\RollType::describe($line->rollLine->labelsPerRoll, $line->rollLine->coreDiameterMm) }}</p>
                    @endif
                    @unless ($line->isValid)
                        <p class="text-sm text-tg-danger">{{ $line->problem === \App\Support\Cart\PricedLine::PROBLEM_QUANTITY ? __('tma.cart.line_quantity', ['min' => $line->minQty]) : __('tma.cart.line_unavailable') }}</p>
                    @endunless
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('tma.cart.update', ['lineKey' => $line->lineKey]) }}" class="flex items-center gap-2">
                            @csrf
                            <label class="sr-only" for="qty-{{ $line->lineKey }}">{{ __('tma.product.quantity') }}</label>
                            <input id="qty-{{ $line->lineKey }}" type="number" name="qty" value="{{ $line->qty }}" min="{{ $line->minQty }}" max="{{ \App\Models\Cart::MAX_QTY }}" step="1" inputmode="numeric" class="tma-input w-24">
                            <button type="submit" class="rounded-lg bg-tg-secondary px-3 py-2 text-sm font-medium">{{ __('tma.cart.update') }}</button>
                        </form>
                        <form method="POST" action="{{ route('tma.cart.remove', ['lineKey' => $line->lineKey]) }}" class="ml-auto">
                            @csrf
                            <button type="submit" class="px-2 py-2 text-sm text-tg-danger">{{ __('tma.cart.remove') }}</button>
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
            <summary class="cursor-pointer text-sm font-medium">{{ __('tma.cart.quote_title') }}</summary>
            <form method="POST" action="{{ route('tma.cart.quote') }}" class="mt-3 grid gap-2" data-cart-quote>
                @csrf
                <textarea name="comment" rows="2" maxlength="500" class="tma-input" placeholder="{{ __('tma.request.comment_placeholder') }}" aria-label="{{ __('tma.request.comment') }}"></textarea>
                <button type="submit" class="tma-btn-outline">{{ __('tma.cart.quote_submit') }}</button>
                <p class="text-xs text-tg-hint">{{ __('tma.request.note') }}</p>
            </form>
        </details>
    @endif
@endsection
