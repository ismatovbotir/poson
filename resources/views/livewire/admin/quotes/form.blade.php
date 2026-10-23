@php
    $backHref = $quote ? route('admin.quotes.show', $quote) : route('admin.quotes.index');
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="$backHref" :back-label="$quote ? $quote->number : __('crm.quotes.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button variant="secondary" :href="$backHref" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Customer --}}
            <x-admin.card :title="__('crm.quotes.fields.customer')">
                @if ($this->customer)
                    <div class="flex flex-wrap items-center gap-2" data-selected-customer>
                        <span class="grid size-10 place-items-center rounded-lg bg-slate-900 text-white"><x-admin.icon name="building" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ $this->customer->name }}</p>
                            <p class="text-xs text-slate-500">@if ($this->customer->inn){{ __('crm.customers.fields.inn') }} {{ $this->customer->inn }} · @endif{{ __('crm.customers.fields.price_level') }}: {{ $this->priceLevel->label() }}</p>
                        </div>
                        @if (! $quote)
                            <button type="button" wire:click="$set('form.customer_id', '')" class="text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __('crm.quotes.change_customer') }}</button>
                        @endif
                    </div>
                @else
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <label class="relative block">
                            <span class="sr-only">{{ __('crm.quotes.customer_search') }}</span>
                            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input wire:model.live.debounce.300ms="customerSearch" x-on:focus="open = true" x-on:input="open = true" type="search" autocomplete="off" placeholder="{{ __('crm.quotes.customer_search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                        </label>
                        @if (mb_strlen(trim($customerSearch)) >= 2)
                            <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-customer-results>
                                @forelse ($this->customerResults as $result)
                                    <button type="button" wire:key="cust-{{ $result->id }}" wire:click="selectCustomer({{ $result->id }})" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                        <span class="min-w-0 truncate font-medium">{{ $result->name }}</span>
                                        <span class="shrink-0 text-xs text-slate-500">{{ $result->price_level->label() }}</span>
                                    </button>
                                @empty
                                    <p class="px-3 py-3 text-sm text-slate-500">{{ __('crm.quotes.no_customers') }}</p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                    <x-admin.field-error name="form.customer_id" />
                @endif
            </x-admin.card>

            {{-- Lines --}}
            <x-admin.card :title="__('crm.quotes.items.title')">
                <x-slot:actions>
                    <x-admin.button size="sm" variant="soft" icon="plus" wire:click="addLine">{{ __('crm.quotes.items.add_line') }}</x-admin.button>
                </x-slot:actions>
                <x-admin.field-error name="form.items" />

                @if ($form->items !== [])
                    <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200" data-quote-items>
                        @foreach ($form->items as $index => $item)
                            @php($lineTotal = (\App\Support\Money::sumsToTiyin((string) ($item['price'] ?? '')) ?? 0) * max(0, (int) ($item['qty'] ?? 0)))
                            <li wire:key="qitem-{{ $item['id'] ?? 'n'.$index.'-'.($item['product_id'] ?? 'x') }}" class="grid gap-3 p-3 sm:grid-cols-[1fr_auto] sm:items-start">
                                <div class="min-w-0">
                                    @if (($item['product_id'] ?? null) !== null || ($item['id'] ?? null) !== null && filled($item['sku'] ?? null))
                                        <p class="truncate text-sm font-semibold">{{ $item['name'] ?? '' }}</p>
                                        @if (filled($item['sku'] ?? null))<p class="font-mono text-xs text-slate-500">{{ $item['sku'] }}</p>@endif
                                    @else
                                        <x-admin.input wire:model="form.items.{{ $index }}.name" :label="__('crm.quotes.items.name')" maxlength="255" :placeholder="__('crm.quotes.items.free_placeholder')" />
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-end gap-2">
                                    <label class="block text-xs text-slate-500">
                                        {{ __('crm.quotes.items.qty') }}
                                        <input wire:model.live.debounce.400ms="form.items.{{ $index }}.qty" type="number" min="1" inputmode="numeric" class="mt-1 h-9 w-20 rounded-lg border border-slate-200 px-2 text-sm tabular-nums outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                                    </label>
                                    <label class="block text-xs text-slate-500">
                                        {{ __('crm.quotes.items.price') }}
                                        <input wire:model.live.debounce.400ms="form.items.{{ $index }}.price" type="text" inputmode="numeric" class="mt-1 h-9 w-36 rounded-lg border border-slate-200 px-2 text-right text-sm tabular-nums outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                                    </label>
                                    <span class="flex h-9 min-w-28 items-center justify-end text-sm font-semibold tabular-nums" data-line-total>{{ $money($lineTotal) }}</span>
                                    <button type="button" wire:click="removeItem({{ $index }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('crm.quotes.items.remove') }}"><x-admin.icon name="trash" size="size-4" /></button>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-admin.field-error :name="'form.items.'.$index.'.qty'" />
                                    <x-admin.field-error :name="'form.items.'.$index.'.price'" />
                                    <x-admin.field-error :name="'form.items.'.$index.'.name'" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="relative mt-3" x-data="{ open: false }" x-on:click.outside="open = false">
                    <label class="relative block">
                        <span class="sr-only">{{ __('crm.quotes.items.search') }}</span>
                        <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input wire:model.live.debounce.300ms="productSearch" x-on:focus="open = true" x-on:input="open = true" type="search" autocomplete="off" placeholder="{{ __('crm.quotes.items.search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                    </label>
                    @if (mb_strlen(trim($productSearch)) >= 2)
                        <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-product-results>
                            @forelse ($this->productResults as $product)
                                @php($levelPrice = $product->priceFor($this->priceLevel))
                                <button type="button" wire:key="qpick-{{ $product->id }}" wire:click="addProduct({{ $product->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $product->name }}</span><span class="block font-mono text-xs text-slate-500">{{ $product->sku }}</span></span>
                                    <span class="shrink-0 text-right text-xs tabular-nums" data-level-price="{{ $levelPrice }}">{{ $levelPrice !== null ? $money($levelPrice) : __('crm.quotes.items.no_price') }}<span class="block text-slate-400">{{ $this->priceLevel->label() }}</span></span>
                                </button>
                            @empty
                                <p class="px-3 py-3 text-sm text-slate-500">{{ __('crm.leads.items.no_results') }}</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                <dl class="mt-4 space-y-2 rounded-xl bg-slate-50 px-4 py-3 text-sm" data-totals>
                    <div class="flex justify-between text-base font-bold"><dt>{{ __('crm.quotes.total') }}</dt><dd class="tabular-nums" data-total>{{ $money($total) }}</dd></div>
                    <div class="flex justify-between text-slate-500"><dt>{{ __('crm.quotes.vat_included', ['percent' => \App\Models\Quote::VAT_PERCENT]) }}</dt><dd class="tabular-nums" data-vat>{{ $money($vat) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-slate-500">{{ __('crm.quotes.prices_hint') }}</p>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('crm.quotes.sections.terms')">
                <div class="space-y-4">
                    <x-admin.input wire:model="form.valid_until" type="date" :label="__('crm.quotes.fields.valid_until')" required :hint="__('crm.quotes.valid_until_hint', ['days' => \App\Models\Quote::VALID_WORKING_DAYS])" />
                    <x-admin.select wire:model="form.locale" :label="__('crm.quotes.fields.locale')" :hint="__('crm.quotes.locale_hint')" :options="collect(config('app.supported_locales'))->mapWithKeys(fn ($l) => [$l => __('admin.locale.'.$l)])->all()" />
                    <x-admin.textarea wire:model="form.notes" :label="__('crm.quotes.fields.notes')" rows="4" maxlength="5000" :hint="__('crm.quotes.notes_hint')" />
                </div>
            </x-admin.card>
        </aside>
    </div>

    <x-admin.sticky-save-bar :cancel-href="$backHref" />
</form>
