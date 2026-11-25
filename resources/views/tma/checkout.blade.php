{{--
    Mini App checkout (CheckoutController::show). The phone is the bot registration's and is only shown.
    Payment radios carry the buyer:delivery combinations they fit (data-pay-for); tma.js hides the rest,
    PlaceOrder decides again. Without any method (individual + region) the cart goes to a manager as a request.
    No online payment, Retail only, "free within Kokand", region cost quoted with the order.
--}}
@extends('layouts.tma')

@php
    $money = fn (?int $tiyin): string => \App\Support\Money::format((int) $tiyin);
    $buyer = old('buyer_type', $defaults['buyer_type']);
    $delivery = old('delivery_type', 'pickup');
    $business = implode(',', array_map(fn ($case) => $case->value, array_filter($buyerTypes, fn ($case) => $case->isBusiness())));
@endphp

@section('content')
    <section class="tma-card grid gap-1 text-sm" data-summary>
        @foreach ($priced->lines as $line)
            <div class="flex justify-between gap-3"><span>{{ $line->product->name }} × {{ $line->qty }}</span><span class="shrink-0">{{ $money($line->total) }}</span></div>
        @endforeach
        <div class="mt-1 flex justify-between border-t border-tg-separator pt-2 text-base font-semibold"><span>{{ __('tma.cart.subtotal') }}</span><span>{{ $money($priced->subtotal) }}</span></div>
    </section>

    <form method="POST" action="{{ route('tma.checkout.store') }}" class="grid gap-3" data-checkout>
        @csrf
        <input type="hidden" name="cart_fingerprint" value="{{ $priced->fingerprint }}">
        <input type="hidden" name="delivery_fingerprint" value="{{ $deliveryFingerprint }}">

        <section class="tma-card grid gap-3">
            <label class="grid gap-1 text-sm">
                <span class="font-medium">{{ __('checkout.fields.name') }}</span>
                <input type="text" name="name" value="{{ old('name', $defaults['name']) }}" required minlength="2" maxlength="120" autocomplete="name" class="tma-input">
            </label>
            <div class="grid gap-1 text-sm">
                <span class="font-medium">{{ __('checkout.fields.phone') }}</span>
                <span class="text-tg-hint" data-phone>{{ \App\Support\Phone::display($phone) }} · {{ __('tma.checkout.phone_from_bot') }}</span>
            </div>

            <fieldset class="grid gap-1.5 text-sm">
                <legend class="mb-1 font-medium">{{ __('checkout.fields.buyer_type') }}</legend>
                @foreach ($buyerTypes as $type)
                    <label class="flex items-center gap-2"><input type="radio" name="buyer_type" value="{{ $type->value }}" @checked($buyer === $type->value) class="size-4"> {{ $type->label() }}</label>
                @endforeach
            </fieldset>

            <div class="grid gap-3" data-show-when="buyer:{{ $business }}">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('checkout.fields.company_name') }}</span>
                    <input type="text" name="company_name" value="{{ old('company_name', $defaults['company_name']) }}" maxlength="160" class="tma-input">
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="font-medium">{{ __('checkout.fields.tin_or_pinfl') }}</span>
                    <input type="text" name="tin" value="{{ old('tin', $defaults['tin']) }}" maxlength="14" inputmode="numeric" class="tma-input">
                </label>
            </div>
        </section>

        <section class="tma-card grid gap-3">
            <fieldset class="grid gap-1.5 text-sm">
                <legend class="mb-1 font-medium">{{ __('checkout.fields.delivery_type') }}</legend>
                @foreach ($deliveryTypes as $type)
                    <label class="flex items-start gap-2">
                        <input type="radio" name="delivery_type" value="{{ $type->value }}" @checked($delivery === $type->value) class="mt-1 size-4">
                        <span>{{ $type->label() }}<span class="block text-xs text-tg-hint">{{ __('tma.checkout.delivery_note.'.$type->value) }}</span></span>
                    </label>
                @endforeach
            </fieldset>

            <label class="grid gap-1 text-sm" data-show-when="delivery:courier_region">
                <span class="font-medium">{{ __('checkout.fields.region') }}</span>
                <select name="region" class="tma-input">
                    <option value="">{{ __('checkout.fields.region_placeholder') }}</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region['value'] }}" @selected(old('region') === $region['value'])>{{ $region['label'] }} — {{ $region['fee'] !== null ? $money($region['fee']) : __('tma.checkout.region_quoted') }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-1 text-sm" data-show-when="delivery:courier_kokand,courier_region">
                <span class="font-medium">{{ __('checkout.fields.address') }}</span>
                <textarea name="address" rows="2" maxlength="500" autocomplete="street-address" class="tma-input">{{ old('address') }}</textarea>
            </label>

            <label class="flex items-center gap-2 text-sm" data-show-when="delivery:courier_kokand">
                <input type="checkbox" name="kokand_confirmed" value="1" @checked(old('kokand_confirmed')) class="size-4"> {{ __('checkout.fields.kokand_confirmed') }}
            </label>
        </section>

        <section class="tma-card grid gap-2 text-sm">
            <fieldset class="grid gap-1.5">
                <legend class="mb-1 font-medium">{{ __('checkout.fields.payment_method') }}</legend>
                @foreach ($methods as $method => $combinations)
                    <label class="flex items-start gap-2" data-pay-for="{{ implode(' ', $combinations) }}">
                        <input type="radio" name="payment_method" value="{{ $method }}" @checked(old('payment_method') === $method) class="mt-1 size-4">
                        <span>{{ \App\Enums\PaymentMethod::from($method)->label() }}<span class="block text-xs text-tg-hint">{{ __('tma.checkout.payment_note.'.$method) }}</span></span>
                    </label>
                @endforeach
            </fieldset>
            <p class="text-xs text-tg-hint">{{ __('tma.checkout.after_order') }}</p>
            <p class="rounded-lg bg-tg-secondary p-3" data-no-method @if ($methods !== []) hidden @endif>{{ __('tma.checkout.no_method') }}</p>
        </section>

        <section class="tma-card grid gap-1 text-sm">
            <label class="grid gap-1">
                <span class="font-medium">{{ __('checkout.fields.comment') }}</span>
                <textarea name="comment" rows="2" maxlength="1000" class="tma-input" placeholder="{{ __('checkout.fields.comment_placeholder') }}">{{ old('comment') }}</textarea>
            </label>
        </section>

        <button type="submit" class="tma-btn" data-main-button="{{ __('tma.checkout.submit') }}" data-place-order>{{ __('tma.checkout.submit') }}</button>
    </form>

    <form method="POST" action="{{ route('tma.cart.quote') }}" class="tma-card grid gap-2" data-checkout-request>
        @csrf
        <p class="text-sm text-tg-hint">{{ __('tma.checkout.request_instead') }}</p>
        <button type="submit" class="tma-btn-outline">{{ __('tma.checkout.send_request') }}</button>
    </form>
@endsection
