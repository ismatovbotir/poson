{{--
    Checkout page (App\Livewire\Site\Checkout\Page, plan 11b). There is no checkout template in
    .claude/html-templates/site: built in the cart page's visual language (breadcrumbs, bordered boxes,
    the lead form inputs). Brand rule: Retail prices shown plainly, "Delivery in Kokand: 0 soʻm",
    no discount or "free" wording. Prices and totals are never Livewire state.
--}}
@php
    $inputClass = 'h-12 w-full rounded-btn border-[1.5px] bg-white px-4 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $money = fn (int $tiyin): string => \App\Support\Money::format($tiyin);
    $choiceClass = 'flex cursor-pointer items-start gap-3 rounded-box border-[1.5px] bg-white p-4 transition-colors has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50/40';
    $field = function (string $name) use ($errors, $inputClass): string {
        return $inputClass.' '.($errors->has($name) ? 'border-danger' : 'border-line');
    };
    $privacyLink = \App\Support\Site\PageDirectory::isActive('privacy')
        ? '<a href="'.e(\App\Support\Site\Navigation::page('privacy')).'" class="font-medium text-brand-600 underline hover:text-brand-700">'.e(__('site.lead_form.consent_privacy')).'</a>'
        : e(__('site.lead_form.consent_privacy'));
@endphp

<div class="site-container pb-12 md:pb-[72px]">
    <x-site.breadcrumbs :items="$breadcrumbs" />

    <h1 class="mb-6 text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('checkout.title') }}</h1>

    @if ($cart === null || $cart->isEmpty())
        <div class="grid justify-items-center gap-4 rounded-box-lg border border-line bg-bg-soft px-6 py-14 text-center" data-checkout-empty>
            <h2 class="text-xl font-bold">{{ __('checkout.cart.empty_title') }}</h2>
            <p class="max-w-md text-ink-2">{{ __('checkout.cart.empty_text') }}</p>
            <a href="{{ route('site.catalog.index') }}" class="inline-flex h-12 items-center justify-center rounded-btn bg-brand-600 px-6 font-semibold text-white hover:bg-brand-700">{{ __('checkout.cart.to_catalog') }}</a>
        </div>
    @else
        <form wire:submit="placeOrder" novalidate class="relative grid items-start gap-8 lg:grid-cols-[1fr_380px]" data-checkout-form>
            <div class="grid gap-6">
                <div role="alert" class="grid gap-3 empty:hidden">
                    @error('fingerprint')
                        <p class="rounded-btn border border-warn/40 bg-warn/5 px-4 py-3 text-sm font-medium text-ink" data-prices-changed>{{ $message }}</p>
                    @enderror
                    @error('form')
                        <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-form-error>{{ $message }}</p>
                    @enderror
                </div>

                @if ($cart->hasInvalidLines())
                    <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-has-invalid>
                        {{ __('checkout.cart.remove_unavailable') }}
                        <a href="{{ route('site.cart') }}" class="underline">{{ __('checkout.back_to_cart') }}</a>
                    </p>
                @endif

                {{-- Buyer --}}
                <section class="grid gap-4 rounded-box-lg border border-line bg-white p-5 sm:p-6" aria-labelledby="checkout-buyer">
                    <h2 id="checkout-buyer" class="text-lg font-bold">{{ __('checkout.sections.buyer') }}</h2>
                    <fieldset class="grid gap-2 sm:grid-cols-3">
                        <legend class="sr-only">{{ __('checkout.fields.buyer_type') }}</legend>
                        @foreach (\App\Enums\BuyerType::cases() as $type)
                            <label class="{{ $choiceClass }} border-line" wire:key="buyer-{{ $type->value }}">
                                <input type="radio" wire:model.live="buyerType" value="{{ $type->value }}" class="mt-1 size-4 accent-brand-600">
                                <span class="text-sm font-semibold">{{ $type->label() }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <label for="checkout-name" class="text-sm font-semibold">{{ __('checkout.fields.name') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="checkout-name" type="text" wire:model="name" autocomplete="name" maxlength="120" aria-required="true" @error('name') aria-invalid="true" @enderror class="{{ $field('name') }}">
                            @error('name')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div class="grid gap-1.5">
                            <label for="checkout-phone" class="text-sm font-semibold">{{ __('checkout.fields.phone') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="checkout-phone" type="tel" inputmode="tel" wire:model="phone" autocomplete="tel" maxlength="32" placeholder="{{ __('site.lead_form.phone_placeholder') }}" aria-required="true" @error('phone') aria-invalid="true" @enderror class="{{ $field('phone') }}">
                            @error('phone')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        @if ($buyerTypeEnum->isBusiness())
                            <div class="grid gap-1.5">
                                <label for="checkout-company" class="text-sm font-semibold">{{ __('checkout.fields.company_name') }} <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="checkout-company" type="text" wire:model="companyName" autocomplete="organization" maxlength="160" @error('companyName') aria-invalid="true" @enderror class="{{ $field('companyName') }}">
                                @error('companyName')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-1.5">
                                <label for="checkout-tin" class="text-sm font-semibold">{{ $buyerTypeEnum === \App\Enums\BuyerType::Company ? __('checkout.fields.tin') : __('checkout.fields.tin_or_pinfl') }} <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="checkout-tin" type="text" inputmode="numeric" wire:model="tin" maxlength="20" @error('tin') aria-invalid="true" @enderror class="{{ $field('tin') }}">
                                <p class="text-[13px] text-ink-2">{{ $buyerTypeEnum === \App\Enums\BuyerType::Company ? __('checkout.hints.stir') : __('checkout.hints.stir_or_pinfl') }}</p>
                                @error('tin')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Delivery --}}
                <section class="grid gap-4 rounded-box-lg border border-line bg-white p-5 sm:p-6" aria-labelledby="checkout-delivery">
                    <h2 id="checkout-delivery" class="text-lg font-bold">{{ __('checkout.sections.delivery') }}</h2>
                    <fieldset class="grid gap-2">
                        <legend class="sr-only">{{ __('checkout.fields.delivery_type') }}</legend>
                        @foreach (\App\Enums\DeliveryType::cases() as $type)
                            <label class="{{ $choiceClass }} border-line" wire:key="delivery-{{ $type->value }}" data-delivery-option="{{ $type->value }}">
                                <input type="radio" wire:model.live="deliveryType" value="{{ $type->value }}" class="mt-1 size-4 accent-brand-600">
                                <span class="grid gap-0.5">
                                    <span class="text-sm font-semibold">{{ $type->label() }}</span>
                                    <span class="text-[13px] text-ink-2">{{ __('checkout.delivery.hints.'.$type->value) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>

                    @if ($deliveryTypeEnum === \App\Enums\DeliveryType::CourierRegion)
                        <div class="grid gap-1.5">
                            <label for="checkout-region" class="text-sm font-semibold">{{ __('checkout.fields.region') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="checkout-region" wire:model.live="region" @error('region') aria-invalid="true" @enderror class="{{ $field('region') }}">
                                <option value="">{{ __('checkout.fields.region_placeholder') }}</option>
                                @foreach (\App\Enums\Region::cases() as $regionCase)
                                    <option value="{{ $regionCase->value }}">{{ $regionCase->label() }} — {{ isset($regionFees[$regionCase->value]) ? $money($regionFees[$regionCase->value] * 100) : __('checkout.delivery.by_manager') }}</option>
                                @endforeach
                            </select>
                            @error('region')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($deliveryTypeEnum->needsAddress())
                        <div class="grid gap-1.5">
                            <label for="checkout-address" class="text-sm font-semibold">{{ __('checkout.fields.address') }} <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="checkout-address" type="text" wire:model="address" autocomplete="street-address" maxlength="500" @error('address') aria-invalid="true" @enderror class="{{ $field('address') }}">
                            @error('address')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($deliveryTypeEnum === \App\Enums\DeliveryType::CourierKokand)
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" wire:model="kokandConfirmed" class="mt-0.5 size-4 accent-brand-600" data-kokand-confirm>
                            <span>{{ __('checkout.fields.kokand_confirmed') }}</span>
                        </label>
                        @error('kokandConfirmed')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    @endif

                    @if ($delivery->isSeparate())
                        <p class="rounded-btn bg-bg-soft px-4 py-3 text-sm text-ink" data-delivery-separate>{{ __('orders.public.delivery_separate') }}</p>
                    @endif
                </section>

                {{-- Payment --}}
                <section class="grid gap-4 rounded-box-lg border border-line bg-white p-5 sm:p-6" aria-labelledby="checkout-payment">
                    <h2 id="checkout-payment" class="text-lg font-bold">{{ __('checkout.sections.payment') }}</h2>
                    @if ($methods === [])
                        <p class="text-sm text-ink-2" data-no-methods>{{ __('checkout.payment.none') }}</p>
                    @else
                        <fieldset class="grid gap-2">
                            <legend class="sr-only">{{ __('checkout.fields.payment_method') }}</legend>
                            @foreach ($methods as $method)
                                <label class="{{ $choiceClass }} border-line" wire:key="method-{{ $method->value }}" data-method="{{ $method->value }}">
                                    <input type="radio" wire:model.live="paymentMethod" value="{{ $method->value }}" class="mt-1 size-4 accent-brand-600">
                                    <span class="grid gap-0.5">
                                        <span class="text-sm font-semibold">{{ $method->label() }}</span>
                                        <span class="text-[13px] text-ink-2">{{ __('checkout.payment.hints.'.$method->value) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                    @error('paymentMethod')<p class="text-sm text-danger">{{ $message }}</p>@enderror

                    <div class="grid gap-1.5">
                        <label for="checkout-comment" class="text-sm font-semibold">{{ __('checkout.fields.comment') }}</label>
                        <textarea id="checkout-comment" wire:model="comment" rows="3" maxlength="1000" placeholder="{{ __('checkout.fields.comment_placeholder') }}" class="{{ str_replace('h-12', 'min-h-24 py-3', $field('comment')) }}"></textarea>
                        @error('comment')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                </section>

                {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
                <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                    <input type="text" id="checkout-fax-ext" name="fax_ext" wire:model="fax_ext" tabindex="-1" autocomplete="off">
                </div>
            </div>

            {{-- Summary --}}
            <aside class="grid gap-4 rounded-box-lg border border-line bg-white p-6 shadow-card lg:sticky lg:top-36" aria-labelledby="checkout-summary" data-checkout-summary>
                <h2 id="checkout-summary" class="text-lg font-bold">{{ __('checkout.cart.summary') }}</h2>
                <ul class="grid gap-2.5 text-sm">
                    @foreach ($cart->lines as $line)
                        <li wire:key="summary-{{ $line->lineKey }}" class="flex justify-between gap-3" data-summary-line="{{ $line->lineKey }}">
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $line->product->isVisible() ? $line->product->name : __('checkout.cart.unavailable_product') }}</span>
                                <span class="text-ink-2">× {{ \App\Support\NumberFormat::format($line->qty) }}@if ($line->isRollLine()) · {{ trans_choice('catalog.roll.rolls', $line->qty, ['count' => \App\Support\NumberFormat::format($line->qty)]) }}@endif</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums">{{ $line->total !== null ? $money($line->total) : __('checkout.cart.unavailable') }}</span>
                        </li>
                    @endforeach
                </ul>
                <dl class="grid gap-2 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-2">{{ __('checkout.summary.goods') }}</dt><dd class="tabular-nums" data-subtotal>{{ $money($cart->subtotal) }}</dd></div>
                    <div class="flex justify-between gap-3" data-delivery-amount>
                        <dt class="text-ink-2">{{ $deliveryTypeEnum === \App\Enums\DeliveryType::CourierKokand ? __('checkout.delivery.kokand_line') : __('checkout.summary.delivery') }}</dt>
                        <dd class="text-right tabular-nums">{{ $delivery->isSeparate() ? __('orders.public.delivery_separate_short') : $money($delivery->amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 text-base">
                        <dt class="font-semibold">{{ __('checkout.cart.total') }}</dt>
                        <dd class="text-2xl font-extrabold tracking-[-0.02em] tabular-nums" data-total>{{ $money($total) }}</dd>
                    </div>
                </dl>
                <p class="text-[13px] text-ink-2">{{ __('checkout.summary.vat', ['percent' => \App\Support\Vat::PERCENT]) }}</p>

                <button type="submit" @disabled($cart->hasInvalidLines() || $methods === []) class="inline-flex h-[54px] w-full items-center justify-center gap-2 rounded-btn bg-brand-600 px-6 text-base font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60" wire:loading.attr="disabled" wire:target="placeOrder" data-place-order>
                    <span wire:loading.remove wire:target="placeOrder">{{ __('checkout.place_order') }}</span>
                    <span wire:loading wire:target="placeOrder">{{ __('site.lead_form.sending') }}</span>
                </button>
                <p class="text-xs text-ink-2">{!! __('site.lead_form.consent', ['privacy' => $privacyLink]) !!}</p>

                <ul class="grid gap-2 border-t border-line pt-4 text-[13px] text-ink-2">
                    <li class="flex items-start gap-2"><x-site.icon name="shield" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.warranty') }}</li>
                    <li class="flex items-start gap-2"><x-site.icon name="wrench" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.setup') }}</li>
                    <li class="flex items-start gap-2"><x-site.icon name="truck" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.delivery') }}</li>
                </ul>
            </aside>
        </form>
    @endif
</div>
