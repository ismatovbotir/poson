{{--
    Mini App checkout (CheckoutController::show). The phone is the bot registration's and is only shown.
    Payment radios carry the buyer:delivery combinations they fit (data-pay-for); tma.js hides the rest,
    PlaceOrder decides again. Without any method (individual + region) the cart goes to a manager as a request.
    No online payment, Retail only, "free within Kokand", region cost quoted with the order.
    Plan 45 §3: one page, four numbered sections (contact, buyer as a segmented control, delivery and
    payment as radio cards), the order summary collapsed; "Send as request" only when no method fits.
--}}
@extends('layouts.tma')

@php
    $money = fn (?int $tiyin): string => \App\Support\Money::format((int) $tiyin);
    $buyer = old('buyer_type', $defaults['buyer_type']);
    $delivery = old('delivery_type', 'pickup');
    $business = implode(',', array_map(fn ($case) => $case->value, array_filter($buyerTypes, fn ($case) => $case->isBusiness())));
    $step = 'flex items-center gap-2 px-1 text-sm font-semibold text-tg-header';
    $number = 'grid size-6 place-items-center rounded-full bg-tg-button text-xs text-tg-button-text';
    $radioCard = 'tma-card flex min-h-12 cursor-pointer items-start gap-3 text-sm has-[:checked]:ring-2 has-[:checked]:ring-tg-button';
    $radio = 'mt-0.5 size-5 shrink-0 accent-tg-button';
@endphp

@section('content')
    <details class="tma-card" data-summary>
        <summary class="tma-row cursor-pointer justify-between font-semibold">
            <span>{{ __('tma.checkout.summary', ['count' => count($priced->lines)]) }}</span>
            <span data-summary-total>{{ $money($priced->subtotal) }}</span>
        </summary>
        <div class="grid gap-1 border-t border-tg-separator pt-2 text-sm">
            @foreach ($priced->lines as $line)
                <div class="flex justify-between gap-3"><span>{{ $line->product->name }} × {{ $line->qty }}</span><span class="shrink-0">{{ $money($line->total) }}</span></div>
            @endforeach
        </div>
    </details>

    <form method="POST" action="{{ route('tma.checkout.store') }}" class="grid gap-5" data-checkout>
        @csrf
        <input type="hidden" name="cart_fingerprint" value="{{ $priced->fingerprint }}">
        <input type="hidden" name="delivery_fingerprint" value="{{ $deliveryFingerprint }}">

        <section class="grid gap-2" aria-labelledby="tma-step-contact">
            <h2 id="tma-step-contact" class="{{ $step }}"><span class="{{ $number }}">1</span> {{ __('tma.checkout.steps.contact') }}</h2>
            <div class="tma-card grid gap-3">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('checkout.fields.name') }}</span>
                    <input type="text" name="name" value="{{ old('name', $defaults['name']) }}" required minlength="2" maxlength="120" autocomplete="name" class="tma-input">
                </label>
                <div class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('checkout.fields.phone') }}</span>
                    <span class="text-tg-hint" data-phone>{{ \App\Support\Phone::display($phone) }} · {{ __('tma.checkout.phone_from_bot') }}</span>
                </div>
            </div>
        </section>

        <section class="grid gap-2" aria-labelledby="tma-step-buyer">
            <h2 id="tma-step-buyer" class="{{ $step }}"><span class="{{ $number }}">2</span> {{ __('tma.checkout.steps.buyer') }}</h2>
            <div class="tma-card grid gap-3">
                <fieldset>
                    <legend class="sr-only">{{ __('checkout.fields.buyer_type') }}</legend>
                    <div class="grid auto-cols-fr grid-flow-col gap-1 rounded-[10px] bg-tg-secondary p-1 text-sm">
                        @foreach ($buyerTypes as $type)
                            <label class="grid min-h-11 cursor-pointer place-items-center rounded-lg px-2 text-center font-medium has-[:checked]:bg-tg-section has-[:checked]:text-tg-link"><input type="radio" name="buyer_type" value="{{ $type->value }}" @checked($buyer === $type->value) class="sr-only"> {{ $type->label() }}</label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="grid gap-3" data-show-when="buyer:{{ $business }}">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">{{ __('checkout.fields.company_name') }}</span>
                        <input type="text" name="company_name" value="{{ old('company_name', $defaults['company_name']) }}" maxlength="160" class="tma-input">
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">{{ __('checkout.fields.tin_or_pinfl') }}</span>
                        <input type="text" name="tin" value="{{ old('tin', $defaults['tin']) }}" maxlength="14" inputmode="numeric" class="tma-input">
                        <span class="text-xs text-tg-hint">{{ __('tma.checkout.tin_hint') }}</span>
                    </label>
                </div>
            </div>
        </section>

        <section class="grid gap-2" aria-labelledby="tma-step-delivery">
            <h2 id="tma-step-delivery" class="{{ $step }}"><span class="{{ $number }}">3</span> {{ __('tma.checkout.steps.delivery') }}</h2>
            <fieldset class="grid gap-2">
                <legend class="sr-only">{{ __('checkout.fields.delivery_type') }}</legend>
                @foreach ($deliveryTypes as $type)
                    <label class="{{ $radioCard }}">
                        <input type="radio" name="delivery_type" value="{{ $type->value }}" @checked($delivery === $type->value) class="{{ $radio }}">
                        <span class="grid"><span class="font-medium">{{ $type->label() }}</span><span class="text-xs text-tg-hint">{{ __('tma.checkout.delivery_note.'.$type->value) }}</span></span>
                    </label>
                @endforeach
            </fieldset>

            <div class="tma-card grid gap-3" data-show-when="delivery:courier_kokand,courier_region">
                <label class="grid gap-1 text-sm" data-show-when="delivery:courier_region">
                    <span class="font-medium">{{ __('checkout.fields.region') }}</span>
                    <select name="region" class="tma-input">
                        <option value="">{{ __('checkout.fields.region_placeholder') }}</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region['value'] }}" @selected(old('region') === $region['value'])>{{ $region['label'] }} — {{ $region['fee'] !== null ? $money($region['fee']) : __('tma.checkout.region_quoted') }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('checkout.fields.address') }}</span>
                    <textarea name="address" rows="2" maxlength="500" autocomplete="street-address" class="tma-input">{{ old('address') }}</textarea>
                </label>

                <label class="tma-row text-sm" data-show-when="delivery:courier_kokand">
                    <input type="checkbox" name="kokand_confirmed" value="1" @checked(old('kokand_confirmed')) class="{{ $radio }}"> <span class="flex-1">{{ __('checkout.fields.kokand_confirmed') }}</span>
                </label>
            </div>
        </section>

        <section class="grid gap-2" aria-labelledby="tma-step-payment">
            <h2 id="tma-step-payment" class="{{ $step }}"><span class="{{ $number }}">4</span> {{ __('tma.checkout.steps.payment') }}</h2>
            <fieldset class="grid gap-2">
                <legend class="sr-only">{{ __('checkout.fields.payment_method') }}</legend>
                @foreach ($methods as $method => $combinations)
                    <label class="{{ $radioCard }}" data-pay-for="{{ implode(' ', $combinations) }}">
                        <input type="radio" name="payment_method" value="{{ $method }}" @checked(old('payment_method') === $method) class="{{ $radio }}">
                        <span class="grid"><span class="font-medium">{{ \App\Enums\PaymentMethod::from($method)->label() }}</span><span class="text-xs text-tg-hint">{{ __('tma.checkout.payment_note.'.$method) }}</span></span>
                    </label>
                @endforeach
            </fieldset>
            <p class="px-1 text-xs text-tg-hint">{{ __('tma.checkout.after_order') }}</p>
            <div class="tma-card grid gap-2 text-sm" data-no-method @if ($methods !== []) hidden @endif>
                <p>{{ __('tma.checkout.no_method') }}</p>
                <button type="submit" form="tma-checkout-request" class="tma-btn-outline">{{ __('tma.checkout.send_request') }}</button>
            </div>
        </section>

        <section class="tma-card grid gap-1 text-sm">
            <label class="grid gap-1">
                <span class="font-medium">{{ __('checkout.fields.comment') }}</span>
                <textarea name="comment" rows="2" maxlength="1000" class="tma-input" placeholder="{{ __('checkout.fields.comment_placeholder') }}">{{ old('comment') }}</textarea>
            </label>
        </section>

        <button type="submit" class="tma-btn" data-main-button="{{ __('tma.checkout.submit') }}" data-place-order>{{ __('tma.checkout.submit') }}</button>
    </form>

    <form method="POST" action="{{ route('tma.cart.quote') }}" id="tma-checkout-request" hidden data-checkout-request>
        @csrf
    </form>
@endsection
