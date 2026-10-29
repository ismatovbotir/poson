{{--
    Cart page (App\Livewire\Site\Cart\Page, plan 11a). There is no cart template in
    .claude/html-templates/site: built in the catalog / product page visual language
    (breadcrumbs, page head, bordered boxes, the roll picker's stepper, the lead form).
    Brand rule: Retail prices only, shown plainly; no old prices or discount wording.
    Lines are referenced by their key only; prices and totals are never Livewire state.
--}}
@php
    $inputClass = 'h-12 w-full rounded-btn border-[1.5px] bg-white px-4 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $fields = [
        'name' => ['type' => 'text', 'autocomplete' => 'name', 'required' => true, 'maxlength' => 120],
        'phone' => ['type' => 'tel', 'autocomplete' => 'tel', 'required' => true, 'maxlength' => 32],
        'company' => ['type' => 'text', 'autocomplete' => 'organization', 'required' => false, 'maxlength' => 160],
    ];
    $privacyLink = \App\Support\Site\PageDirectory::isActive('privacy')
        ? '<a href="'.e(\App\Support\Site\Navigation::page('privacy')).'" class="font-medium text-brand-600 underline hover:text-brand-700">'.e(__('site.lead_form.consent_privacy')).'</a>'
        : e(__('site.lead_form.consent_privacy'));
    $money = fn (int $tiyin): string => \App\Support\Money::format($tiyin);
@endphp

<div class="site-container pb-12 md:pb-[72px]">
    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="mb-6">
        <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('checkout.cart.title') }}</h1>
        @if ($cart !== null && ! $cart->isEmpty())
            <p class="text-ink-2" data-lines-count>{{ trans_choice('checkout.cart.lines', $cart->count(), ['count' => $cart->count()]) }}</p>
        @endif
    </div>

    <div role="alert">
        @error('cart')
            <p class="mb-5 rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-cart-error>{{ $message }}</p>
        @enderror
    </div>

    @if ($cart === null || $cart->isEmpty())
        <div class="grid justify-items-center gap-4 rounded-box-lg border border-line bg-bg-soft px-6 py-14 text-center" data-cart-empty>
            <span class="grid size-14 place-items-center rounded-full bg-white text-ink-2">
                <x-site.icon name="cart" size="size-7" />
            </span>
            <h2 class="text-xl font-bold">{{ __('checkout.cart.empty_title') }}</h2>
            <p class="max-w-md text-ink-2">{{ __('checkout.cart.empty_text') }}</p>
            <a href="{{ route('site.catalog.index') }}" class="inline-flex h-12 items-center justify-center rounded-btn bg-brand-600 px-6 font-semibold text-white transition-colors hover:bg-brand-700">{{ __('checkout.cart.to_catalog') }}</a>
        </div>
    @else
        <div class="grid items-start gap-8 lg:grid-cols-[1fr_360px]">
            {{-- Lines --}}
            <ul class="grid gap-3.5" data-cart-lines>
                @foreach ($cart->lines as $line)
                    @php
                        $product = $line->product;
                        // A product no longer on the site shows only its SKU and a neutral label (never its current name or image).
                        $visible = $product->isVisible();
                        $image = $visible ? $product->imageUrl('thumb') : null;
                        $productUrl = $visible ? route('site.product', ['product' => $product->slug]) : null;
                        $productName = $visible ? (string) $product->name : __('checkout.cart.unavailable_product');
                    @endphp
                    <li
                        wire:key="line-{{ $line->lineKey }}"
                        @class([
                            'grid grid-cols-[72px_1fr] gap-4 rounded-box-lg border bg-white p-4 sm:grid-cols-[96px_1fr] sm:p-5',
                            'border-line' => $line->isValid,
                            'border-danger/40 bg-danger/5' => ! $line->isValid,
                        ])
                        data-line="{{ $line->lineKey }}"
                        data-valid="{{ $line->isValid ? 'true' : 'false' }}"
                    >
                        <div class="grid aspect-square place-items-center overflow-hidden rounded-box bg-bg-soft">
                            @if ($image)
                                <img src="{{ $image }}" alt="" width="96" height="96" loading="lazy" class="size-[84%] object-contain">
                            @else
                                <svg viewBox="0 0 120 120" class="size-[62%]" aria-hidden="true" focusable="false"><use href="#{{ $visible ? \App\Support\Catalog\ProductCards::placeholder($product->category_id) : 'p-barcode' }}" /></svg>
                            @endif
                        </div>

                        <div class="grid min-w-0 gap-3 sm:grid-cols-[1fr_auto] sm:gap-5">
                            <div class="min-w-0">
                                <h2 class="text-[15px] leading-[1.35] font-semibold">
                                    @if ($productUrl)
                                        <a href="{{ $productUrl }}" class="hover:text-brand-600">{{ $productName }}</a>
                                    @else
                                        <span data-hidden-product>{{ $productName }}</span>
                                    @endif
                                </h2>
                                <p class="mt-0.5 text-[13px] text-ink-2">{{ __('site.product.sku') }}: {{ $product->sku }}</p>

                                @if ($line->rollLine)
                                    <p class="mt-1 text-[13px] text-ink-2" data-line-roll>{{ \App\Support\Catalog\RollPricing::describe($line->rollLine->labelSize, $line->rollLine->labelsPerRoll, $line->rollLine->coreDiameterMm) }}</p>
                                @endif

                                @if ($line->isUnavailable())
                                    <p class="mt-2 text-sm font-semibold text-danger" data-line-unavailable>{{ __('checkout.cart.unavailable') }}</p>
                                    <p class="text-[13px] text-ink-2">{{ __('checkout.cart.unavailable_hint') }}</p>
                                @elseif ($line->problem === \App\Support\Cart\PricedLine::PROBLEM_QUANTITY)
                                    <p class="mt-2 text-sm font-semibold text-danger" data-line-quantity>{{ __('checkout.cart.errors.quantity', ['min' => \App\Support\NumberFormat::format($line->minQty), 'max' => \App\Support\NumberFormat::format($maxQty)]) }}</p>
                                @elseif ($line->isOnOrder())
                                    <p class="mt-2 inline-flex items-center gap-1.5 text-[13px] font-medium text-warn" data-line-on-order>
                                        <x-site.icon name="clock" size="size-4" />
                                        {{ __('checkout.cart.on_order_note') }}
                                    </p>
                                @endif

                                @if ($line->unitPrice !== null && ! $line->isUnavailable())
                                    <p class="mt-2 text-[13px] text-ink-2" data-unit-price>
                                        {{ $line->isRollLine() ? __('checkout.cart.per_roll', ['price' => $money($line->unitPrice)]) : __('checkout.cart.per_unit', ['price' => $money($line->unitPrice), 'unit' => $product->unit->label()]) }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 sm:flex-col sm:items-end sm:justify-start">
                                @unless ($line->isUnavailable())
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" wire:click="decrement('{{ $line->lineKey }}')" @disabled($line->qty <= $line->minQty) class="grid size-10 place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('checkout.cart.decrease') }}">−</button>
                                        <label for="qty-{{ $line->lineKey }}" class="sr-only">{{ __('checkout.cart.quantity') }}</label>
                                        {{-- Keyed per render: a rejected value is replaced by the stored quantity. --}}
                                        <input
                                            wire:key="qty-{{ $line->lineKey }}-{{ $renderId }}"
                                            id="qty-{{ $line->lineKey }}"
                                            type="number"
                                            value="{{ $line->qty }}"
                                            min="{{ $line->minQty }}"
                                            max="{{ $maxQty }}"
                                            step="1"
                                            inputmode="numeric"
                                            wire:change="updateQty('{{ $line->lineKey }}', $event.target.value)"
                                            class="h-10 w-20 rounded-btn border-[1.5px] border-line bg-white px-2 text-center font-semibold tabular-nums focus:border-brand-600 focus:outline-none"
                                            data-qty
                                        >
                                        <button type="button" wire:click="increment('{{ $line->lineKey }}')" @disabled($line->qty >= $maxQty) class="grid size-10 place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('checkout.cart.increase') }}">+</button>
                                    </div>
                                    @if ($line->isRollLine())
                                        <span class="text-[13px] text-ink-2">{{ trans_choice('catalog.roll.rolls', $line->qty, ['count' => \App\Support\NumberFormat::format($line->qty)]) }}</span>
                                    @endif
                                @endunless

                                @if ($line->total !== null)
                                    <p class="text-lg font-extrabold tracking-[-0.02em] tabular-nums" data-line-total>{{ $money($line->total) }}</p>
                                @endif

                                <button type="button" wire:click="remove('{{ $line->lineKey }}')" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-2 transition-colors hover:text-danger" data-remove>
                                    <x-site.icon name="close" size="size-4" />
                                    {{ __('checkout.cart.remove') }}<span class="sr-only">: {{ $productName }} ({{ $product->sku }})</span>
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Summary --}}
            <aside class="grid gap-4 rounded-box-lg border border-line bg-white p-6 shadow-card lg:sticky lg:top-36" aria-labelledby="cart-summary-title" data-cart-summary>
                <h2 id="cart-summary-title" class="text-lg font-bold">{{ __('checkout.cart.summary') }}</h2>
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-3 text-base">
                        <dt class="font-semibold">{{ __('checkout.cart.total') }}</dt>
                        <dd class="text-2xl font-extrabold tracking-[-0.02em] tabular-nums" data-subtotal>{{ $money($cart->subtotal) }}</dd>
                    </div>
                </dl>
                <p class="text-[13px] text-ink-2">{{ __('checkout.cart.vat_included') }}</p>

                @if ($cart->hasInvalidLines())
                    <p class="rounded-btn bg-danger/5 px-3.5 py-2.5 text-[13px] font-medium text-danger" data-has-invalid>{{ __('checkout.cart.remove_unavailable') }}</p>
                @endif

                <ul class="grid gap-2 border-t border-line pt-4 text-[13px] text-ink-2">
                    <li class="flex items-start gap-2"><x-site.icon name="shield" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.warranty') }}</li>
                    <li class="flex items-start gap-2"><x-site.icon name="wrench" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.setup') }}</li>
                    <li class="flex items-start gap-2"><x-site.icon name="truck" size="size-4" class="mt-0.5 text-brand-600" />{{ __('checkout.cart.benefits.delivery') }}</li>
                </ul>

                @if ($cart->hasInvalidLines())
                    <span class="inline-flex h-12 cursor-not-allowed items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white opacity-50" aria-disabled="true" data-checkout-disabled>{{ __('checkout.cart.checkout') }}</span>
                @else
                    <a href="{{ route('site.checkout') }}" class="inline-flex h-12 items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white transition-colors hover:bg-brand-700" data-checkout-link>{{ __('checkout.cart.checkout') }}</a>
                @endif
                <a href="#cart-quote" class="inline-flex h-12 items-center justify-center rounded-btn border-[1.5px] border-brand-600 px-5 font-semibold text-brand-600 transition-colors hover:bg-brand-50" data-quote-link>{{ __('checkout.cart.quote.button') }}</a>
            </aside>
        </div>
    @endif

    {{-- Request a quote for this cart --}}
    @if (($cart !== null && ! $cart->isEmpty()) || $sent)
        <section id="cart-quote" class="mt-12 scroll-mt-28 md:mt-[72px]" aria-labelledby="cart-quote-title">
            <div class="grid items-start gap-8 rounded-[28px] bg-bg-tint px-6 py-8 md:grid-cols-2 md:gap-12 md:p-14">
                <div>
                    <h2 id="cart-quote-title" class="mb-3 text-[28px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[32px]">{{ __('checkout.cart.quote.title') }}</h2>
                    <p class="text-ink-2">{{ __('checkout.cart.quote.text') }}</p>
                </div>

                <div class="rounded-box-lg bg-white p-5 shadow-card sm:p-7">
                    <div role="status" aria-live="polite" aria-atomic="true">
                        @if ($sent)
                            <div class="flex flex-col items-start gap-3 py-4 focus:outline-none" tabindex="-1" x-init="$nextTick(() => $el.focus())" data-lead-success>
                                <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600">
                                    <x-site.icon name="check" size="size-6" />
                                </span>
                                <h3 class="text-xl font-bold">{{ __('site.lead_form.success_title') }}</h3>
                                <p class="text-ink-2">{{ __('checkout.cart.quote.success_text') }}</p>
                            </div>
                        @endif
                    </div>

                    @unless ($sent)
                        <form wire:submit="requestQuote" novalidate class="relative grid gap-3.5" data-cart-quote-form>
                            <div role="alert">
                                @error('form')
                                    <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-form-error>{{ $message }}</p>
                                @enderror
                            </div>

                            @foreach ($fields as $field => $options)
                                <div class="grid gap-1.5" wire:key="cart-quote-field-{{ $field }}">
                                    <label for="cart-quote-{{ $field }}" class="text-sm font-semibold">
                                        {{ __("site.lead_form.{$field}") }}
                                        @if ($options['required'])
                                            <span class="text-danger" aria-hidden="true">*</span>
                                        @endif
                                    </label>
                                    <input
                                        id="cart-quote-{{ $field }}"
                                        type="{{ $options['type'] }}"
                                        wire:model="{{ $field }}"
                                        autocomplete="{{ $options['autocomplete'] }}"
                                        maxlength="{{ $options['maxlength'] }}"
                                        @if ($field === 'phone') inputmode="tel" @endif
                                        placeholder="{{ __("site.lead_form.{$field}_placeholder") }}"
                                        @if ($options['required']) aria-required="true" @endif
                                        @error($field) aria-invalid="true" aria-describedby="cart-quote-{{ $field }}-error" @enderror
                                        @class([$inputClass, 'border-danger' => $errors->has($field), 'border-line' => ! $errors->has($field)])
                                    >
                                    @error($field)
                                        <p id="cart-quote-{{ $field }}-error" class="text-sm text-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach

                            <div class="grid gap-1.5">
                                <label for="cart-quote-message" class="text-sm font-semibold">{{ __('site.lead_form.message') }}</label>
                                <textarea
                                    id="cart-quote-message"
                                    wire:model="message"
                                    rows="3"
                                    maxlength="1000"
                                    placeholder="{{ __('checkout.cart.quote.message_placeholder') }}"
                                    @error('message') aria-invalid="true" aria-describedby="cart-quote-message-error" @enderror
                                    @class([str_replace('h-12', 'min-h-24 py-3', $inputClass), 'border-danger' => $errors->has('message'), 'border-line' => ! $errors->has('message')])
                                ></textarea>
                                @error('message')
                                    <p id="cart-quote-message-error" class="text-sm text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
                            <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                                <input type="text" id="cart-quote-fax-ext" name="fax_ext" wire:model="fax_ext" tabindex="-1" autocomplete="off">
                            </div>

                            <button
                                type="submit"
                                class="mt-1 inline-flex h-[54px] w-full items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-wait disabled:opacity-80"
                                wire:loading.attr="disabled"
                                wire:target="requestQuote"
                            >
                                <span wire:loading.remove wire:target="requestQuote">{{ __('checkout.cart.quote.submit') }}</span>
                                <span wire:loading wire:target="requestQuote">{{ __('site.lead_form.sending') }}</span>
                            </button>

                            <p class="text-xs text-ink-2">{!! __('site.lead_form.consent', ['privacy' => $privacyLink]) !!}</p>
                        </form>
                    @endunless
                </div>
            </div>
        </section>
    @endif
</div>
