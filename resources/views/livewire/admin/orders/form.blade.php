@php
    $backHref = $order ? route('admin.orders.show', $order) : route('admin.orders.index');
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $buyerBusiness = \App\Enums\BuyerType::tryFrom($form->buyer_type)?->isBusiness() ?? false;
    $deliveryType = \App\Enums\DeliveryType::tryFrom($form->delivery_type);
@endphp

{{-- Manual order (create) or order edit (plan 11b). Sections lock per the editing rules; the actions re-check them. --}}
<form wire:submit="save" novalidate data-order-form>
    <x-admin.page-header :title="$title" :back-href="$backHref" :back-label="$order ? $order->number : __('orders.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button variant="secondary" :href="$backHref" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    @if ($order && $isSite)
        <p class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900" data-site-order-note>{{ __('orders.form.site_note') }}</p>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Customer (CRM link; its level prices new lines of a manual order) --}}
            <x-admin.card :title="__('orders.fields.customer')" :description="__('orders.form.customer_hint')">
                @if ($this->customer)
                    <div class="flex flex-wrap items-center gap-2" data-selected-customer>
                        <span class="grid size-10 place-items-center rounded-lg bg-slate-900 text-white"><x-admin.icon name="building" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ $this->customer->name }}</p>
                            <p class="text-xs text-slate-500">@if ($this->customer->inn){{ __('orders.fields.tin') }} {{ $this->customer->inn }} · @endif{{ __('orders.fields.price_level') }}: {{ $this->priceLevel->label() }}</p>
                        </div>
                        @if ($detailsEditable)
                            <button type="button" wire:click="clearCustomer" class="text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __('orders.form.change_customer') }}</button>
                        @endif
                    </div>
                @elseif ($detailsEditable)
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <label class="relative block">
                            <span class="sr-only">{{ __('orders.form.customer_search') }}</span>
                            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input wire:model.live.debounce.300ms="customerSearch" x-on:focus="open = true" x-on:input="open = true" type="search" autocomplete="off" placeholder="{{ __('orders.form.customer_search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                        </label>
                        @if (mb_strlen(trim($customerSearch)) >= 2)
                            <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-customer-results>
                                @forelse ($this->customerResults as $result)
                                    <button type="button" wire:key="cust-{{ $result->id }}" wire:click="selectCustomer({{ $result->id }})" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 truncate font-medium">{{ $result->name }}</span>
                                        <span class="shrink-0 text-xs text-slate-500">{{ $result->price_level->label() }}</span>
                                    </button>
                                @empty
                                    <p class="px-3 py-3 text-sm text-slate-500">{{ __('orders.form.no_customers') }}</p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-slate-500">{{ __('admin.common.empty_value') }}</p>
                @endif
            </x-admin.card>

            {{-- Buyer --}}
            <x-admin.card :title="__('orders.sections.buyer')">
                <fieldset class="grid gap-4 sm:grid-cols-2" @disabled(! $detailsEditable)>
                    <x-admin.select class="sm:col-span-2" wire:model.live="form.buyer_type" :label="__('orders.fields.buyer_type')" :options="collect(\App\Enums\BuyerType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" required />
                    <x-admin.input wire:model="form.name" :label="__('orders.fields.name')" maxlength="120" required />
                    <x-admin.input wire:model="form.phone" type="tel" :label="__('orders.fields.phone')" maxlength="32" placeholder="+998 90 123 45 67" required />
                    @if ($buyerBusiness)
                        <x-admin.input wire:model="form.company_name" :label="__('orders.fields.company_name')" maxlength="160" required />
                        <x-admin.input wire:model="form.tin" inputmode="numeric" :label="__('orders.fields.tin')" :hint="__('orders.form.tin_hint')" maxlength="20" required />
                    @endif
                    <x-admin.textarea class="sm:col-span-2" wire:model="form.comment" :label="__('orders.fields.comment')" rows="2" maxlength="1000" />
                </fieldset>
            </x-admin.card>

            {{-- Lines --}}
            <x-admin.card :title="__('orders.items.title')">
                @if ($canFreeLine && $linesEditable)
                    <x-slot:actions>
                        <x-admin.button size="sm" variant="soft" icon="plus" wire:click="addLine">{{ __('orders.items.add_line') }}</x-admin.button>
                    </x-slot:actions>
                @endif
                @unless ($linesEditable)
                    <p class="mb-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600" data-lines-locked>{{ __('orders.errors.lines_locked') }}</p>
                @endunless
                <x-admin.field-error name="form.items" />

                @if ($form->items !== [])
                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200" data-order-items>
                        @foreach ($form->items as $index => $item)
                            @php($isRoll = \App\Livewire\Forms\Admin\Orders\OrderForm::isRollRow($item))
                            @php($unitPrice = \App\Livewire\Forms\Admin\Orders\OrderForm::rowUnitPrice($item))
                            <li wire:key="oitem-{{ $item['uid'] ?? $index }}" class="grid gap-3 p-3 sm:grid-cols-[1fr_auto] sm:items-start">
                                <div class="min-w-0">
                                    @if (($item['product_id'] ?? null) !== null || ($item['id'] ?? null) !== null)
                                        <p class="truncate text-sm font-semibold">{{ $item['name'] ?? '' }}</p>
                                        @if (filled($item['sku'] ?? null))<p class="font-mono text-xs text-slate-500">{{ $item['sku'] }}</p>@endif
                                    @else
                                        <x-admin.input wire:model="form.items.{{ $index }}.name" :label="__('orders.items.name')" maxlength="255" />
                                    @endif
                                    @if ($isRoll)
                                        @if (($item['id'] ?? null) === null && ($item['product_id'] ?? null) !== null)
                                            <label class="mt-2 block text-xs text-slate-500">
                                                {{ __('orders.items.roll_type') }}
                                                <select wire:model.live="form.items.{{ $index }}.roll_type_id" class="mt-1 h-9 w-full rounded-lg border border-slate-200 bg-white px-2 text-sm text-slate-900 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                                                    @if (blank($item['roll_type_id'] ?? null))<option value="">{{ __('admin.common.choose') }}</option>@endif
                                                    @foreach ($this->rollTypeOptions[(int) $item['product_id']] ?? [] as $option)
                                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @else
                                            <p class="mt-1 text-xs text-slate-600" data-roll-line>{{ \App\Support\Catalog\RollPricing::describe($item['label_size'] ?? null, (int) $item['labels_per_roll'], $item['core_diameter_mm'] ?? null) }}</p>
                                        @endif
                                    @endif
                                    @if (! empty($item['edited']))<p class="mt-1 text-xs font-semibold text-amber-700">{{ __('orders.items.edited_flag') }}</p>@endif
                                </div>
                                <div class="flex flex-wrap items-end gap-2">
                                    <label class="block text-xs text-slate-500">
                                        {{ $isRoll ? __('orders.items.rolls') : __('orders.items.qty') }}
                                        <input wire:model.live.debounce.400ms="form.items.{{ $index }}.qty" type="number" min="1" max="10000" inputmode="numeric" @disabled(! $linesEditable) class="mt-1 h-9 w-20 rounded-lg border border-slate-200 px-2 text-sm tabular-nums outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 disabled:bg-slate-50">
                                    </label>
                                    <label class="block text-xs text-slate-500">
                                        {{ $isRoll ? __('orders.items.price_per_label') : __('orders.items.price') }}
                                        <input wire:model.live.debounce.400ms="form.items.{{ $index }}.{{ $isRoll ? 'price_per_label' : 'price' }}" type="text" inputmode="numeric" @disabled(! $linesEditable || $isSite) class="mt-1 h-9 w-32 rounded-lg border border-slate-200 px-2 text-right text-sm tabular-nums outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 disabled:bg-slate-50" data-price-input>
                                    </label>
                                    <span class="flex h-9 min-w-28 items-center justify-end text-sm font-semibold tabular-nums" data-line-total>{{ $money($unitPrice * max(0, min(10000, (int) ($item['qty'] ?? 0)))) }}</span>
                                    @if ($linesEditable)
                                        <button type="button" wire:click="removeItem({{ $index }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('orders.items.remove') }}"><x-admin.icon name="trash" size="size-4" /></button>
                                    @endif
                                </div>
                                <div class="sm:col-span-2">
                                    @foreach (['qty', 'price', 'price_per_label', 'roll_type_id', 'name'] as $errorField)
                                        <x-admin.field-error :name="'form.items.'.$index.'.'.$errorField" />
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($linesEditable)
                    <div class="relative mt-3" x-data="{ open: false }" x-on:click.outside="open = false">
                        <label class="relative block">
                            <span class="sr-only">{{ __('orders.items.search') }}</span>
                            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input wire:model.live.debounce.300ms="productSearch" x-on:focus="open = true" x-on:input="open = true" type="search" autocomplete="off" placeholder="{{ __('orders.items.search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                        </label>
                        @if (mb_strlen(trim($productSearch)) >= 2)
                            <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-product-results>
                                @forelse ($this->productResults as $product)
                                    @php($levelPrice = $product->priceFor($this->priceLevel))
                                    <button type="button" wire:key="opick-{{ $product->id }}" wire:click="addProduct({{ $product->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $product->name }}</span><span class="block font-mono text-xs text-slate-500">{{ $product->sku }}</span></span>
                                        <span class="shrink-0 text-right text-xs tabular-nums">{{ $levelPrice === null ? __('orders.items.no_price') : ($product->isPricedPerLabel() ? __('catalog.roll.per_label', ['price' => $money($levelPrice)]) : $money($levelPrice)) }}<span class="block text-slate-400">{{ $this->priceLevel->label() }}</span></span>
                                    </button>
                                @empty
                                    <p class="px-3 py-3 text-sm text-slate-500">{{ __('crm.leads.items.no_results') }}</p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endif

                <dl class="mt-4 space-y-2 rounded-xl bg-slate-50 px-4 py-3 text-sm" data-totals>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.totals.goods') }}</dt><dd class="tabular-nums">{{ $money($goods) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.totals.delivery', ['type' => $deliveryType?->label() ?? '']) }}</dt><dd class="tabular-nums">{{ $deliverySeparate ? __('orders.public.delivery_separate_short') : $money($deliveryAmount) }}</dd></div>
                    <div class="flex justify-between text-base font-bold"><dt>{{ __('orders.totals.total') }}</dt><dd class="tabular-nums" data-total>{{ $money($total) }}</dd></div>
                    <div class="flex justify-between text-slate-500"><dt>{{ __('orders.invoice.vat', ['percent' => \App\Support\Vat::PERCENT]) }}</dt><dd class="tabular-nums">{{ $money($vat) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-slate-500">{{ $isSite ? __('orders.form.site_prices_hint') : __('orders.form.prices_hint') }}</p>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            @if ($canTest)
                <x-admin.card :title="__('orders.form.test_title')">
                    <x-admin.toggle wire:model.live="form.is_test" :label="__('orders.form.test_label')" :description="__('orders.form.test_hint')" data-test-toggle />
                </x-admin.card>
            @endif

            <x-admin.card :title="__('orders.sections.delivery')">
                <fieldset class="space-y-4" @disabled(! $deliveryEditable)>
                    <x-admin.select wire:model.live="form.delivery_type" :label="__('orders.fields.delivery_type')" :options="collect(\App\Enums\DeliveryType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" required />
                    @if ($deliveryType === \App\Enums\DeliveryType::CourierRegion)
                        <x-admin.select wire:model.live="form.region" :label="__('orders.fields.region')" :placeholder="__('admin.common.choose')" :options="collect(\App\Enums\Region::cases())->mapWithKeys(fn ($region) => [$region->value => $region->label()])->all()" required />
                        @if ($linesEditable)
                            <x-admin.input wire:model.live.debounce.400ms="form.delivery_fee" inputmode="numeric" :label="__('orders.fields.delivery_fee')" :hint="__('orders.form.delivery_fee_hint')" maxlength="20" />
                        @endif
                    @endif
                    @if ($deliveryType?->needsAddress())
                        <x-admin.textarea wire:model="form.address" :label="__('orders.fields.address')" rows="2" maxlength="500" required />
                    @endif
                    @if ($deliveryType === \App\Enums\DeliveryType::CourierKokand)
                        <x-admin.toggle wire:model="form.kokand_confirmed" :label="__('orders.kokand_confirmed')" />
                    @endif
                </fieldset>
            </x-admin.card>

            <x-admin.card :title="__('orders.sections.payment')">
                <fieldset class="space-y-4" @disabled(! $linesEditable)>
                    <x-admin.select wire:model="form.payment_method" :label="__('orders.fields.payment_method')" :options="collect($methods)->mapWithKeys(fn ($method) => [$method->value => $method->label()])->all()" required />
                    <x-admin.field-error name="form.payment_method" />
                </fieldset>
            </x-admin.card>

            <x-admin.card :title="__('orders.sections.manager')">
                <fieldset class="space-y-4" @disabled(! $detailsEditable)>
                    <x-admin.select wire:model="form.manager_id" :label="__('orders.fields.manager')" :placeholder="__('admin.common.choose')" :options="$this->managerOptions" />
                    <x-admin.select wire:model="form.locale" :label="__('orders.fields.locale')" :options="collect(config('app.supported_locales'))->mapWithKeys(fn ($l) => [$l => __('admin.locale.'.$l)])->all()" :disabled="$order !== null" />
                    <x-admin.textarea wire:model="form.staff_note" :label="__('orders.fields.staff_note')" :hint="__('orders.form.staff_note_hint')" rows="3" maxlength="2000" />
                </fieldset>
            </x-admin.card>
        </aside>
    </div>

    <x-admin.sticky-save-bar :cancel-href="$backHref" />
</form>
