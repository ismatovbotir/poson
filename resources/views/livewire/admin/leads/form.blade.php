@php
    $backHref = $lead ? route('admin.leads.show', $lead) : route('admin.leads.index');
    $duplicates = $this->duplicates;
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="$backHref" :back-label="$lead ? $lead->reference() : __('crm.leads.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button variant="secondary" :href="$backHref" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('crm.leads.sections.contact')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.input wire:model="form.name" :label="__('crm.leads.fields.name')" required maxlength="120" autocomplete="off" />
                    <div>
                        <x-admin.input wire:model.live.debounce.500ms="form.phone" type="tel" inputmode="tel" :label="__('crm.leads.fields.phone')" required maxlength="32" placeholder="+998 90 123 45 67" autocomplete="off" />
                    </div>
                    <x-admin.input wire:model="form.company" :label="__('crm.leads.fields.company')" maxlength="160" autocomplete="off" />
                    <x-admin.input wire:model="form.inn" :label="__('crm.leads.fields.inn')" inputmode="numeric" maxlength="9" :hint="__('crm.leads.inn_hint')" autocomplete="off" />
                    <x-admin.input wire:model="form.email" type="email" :label="__('crm.leads.fields.email')" maxlength="255" autocomplete="off" class="sm:col-span-2" />
                </div>

                {{-- Live duplicate / customer hint --}}
                @if ($duplicates['visible']->isNotEmpty() || $duplicates['hidden'] || $this->matchedCustomer)
                    <div class="mt-5 space-y-2" aria-live="polite" data-duplicate-warning>
                        @if ($duplicates['visible']->isNotEmpty() || $duplicates['hidden'])
                            <div class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                                <p class="flex items-center gap-1.5 font-semibold"><x-admin.icon name="alert" size="size-4" />{{ __('crm.leads.duplicate_warning', ['days' => \App\Models\Lead::DUPLICATE_WINDOW_DAYS]) }}</p>
                                @if ($duplicates['visible']->isNotEmpty())
                                    <ul class="mt-1.5 space-y-1">
                                        @foreach ($duplicates['visible'] as $match)
                                            <li wire:key="dup-{{ $match->id }}">
                                                <a href="{{ route('admin.leads.show', $match) }}" wire:navigate class="font-mono font-semibold underline">{{ $match->reference() }}</a>
                                                · {{ $match->status->label() }} · {{ $match->manager?->name ?? __('crm.leads.unassigned') }}
                                                · {{ \App\Support\DateFormat::iso($match->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE), 'D MMM') }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($duplicates['hidden'])
                                    <p class="mt-1.5 text-xs">{{ __('crm.leads.duplicate_hidden') }}</p>
                                @endif
                            </div>
                        @endif
                        @if ($this->matchedCustomer)
                            <p class="flex items-center gap-1.5 rounded-lg bg-brand-50 px-3 py-2.5 text-sm text-brand-800" data-customer-match>
                                <x-admin.icon name="building" size="size-4" />{{ __('crm.leads.customer_match', ['name' => $this->matchedCustomer->name]) }}
                            </p>
                        @endif
                    </div>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('crm.leads.sections.request')">
                <div class="space-y-5">
                    <x-admin.textarea wire:model="form.message" :label="__('crm.leads.fields.message')" rows="4" maxlength="5000" />

                    {{-- Items --}}
                    <div>
                        <p class="text-sm font-semibold">{{ __('crm.leads.items.title') }}</p>
                        <x-admin.field-error name="form.items" />

                        @if ($form->items !== [])
                            <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
                                @foreach ($form->items as $index => $item)
                                    @php($itemName = (string) ($item['name'] ?? ''))
                                    <li wire:key="item-{{ $item['id'] ?? 'p'.($item['product_id'] ?? $index) }}" class="flex flex-col gap-3 p-3 sm:flex-row sm:items-start">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold">{{ $itemName }}</p>
                                            @if ($item['sku'] ?? null)<p class="font-mono text-xs text-slate-500">{{ $item['sku'] }}</p>@endif
                                            <input wire:model="form.items.{{ $index }}.note" type="text" maxlength="500" placeholder="{{ __('crm.leads.items.note') }}" aria-label="{{ __('crm.leads.items.note') }}: {{ $itemName }}" class="mt-2 h-9 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                                            <x-admin.field-error :name="'form.items.'.$index.'.note'" />
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <label class="flex items-center gap-2 text-xs text-slate-500">
                                                {{ __('crm.leads.items.qty') }}
                                                <input wire:model="form.items.{{ $index }}.qty" type="number" min="1" inputmode="numeric" class="h-9 w-20 rounded-lg border border-slate-200 px-2 text-sm tabular-nums outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                                            </label>
                                            <button type="button" wire:click="removeItem({{ $index }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('crm.leads.items.remove') }}: {{ $itemName }}"><x-admin.icon name="trash" size="size-4" /></button>
                                        </div>
                                        <x-admin.field-error :name="'form.items.'.$index.'.qty'" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="relative mt-3" x-data="{ open: false }" x-on:click.outside="open = false">
                            <label class="relative block">
                                <span class="sr-only">{{ __('crm.leads.items.search') }}</span>
                                <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input wire:model.live.debounce.300ms="productSearch" x-on:focus="open = true" x-on:input="open = true" type="search" autocomplete="off" placeholder="{{ __('crm.leads.items.search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                            </label>
                            @if (mb_strlen(trim($productSearch)) >= 2)
                                <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-product-results>
                                    @forelse ($this->productResults as $product)
                                        <button type="button" wire:key="pick-{{ $product->id }}" wire:click="addProduct({{ $product->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $product->name }}</span><span class="block font-mono text-xs text-slate-500">{{ $product->sku }}</span></span>
                                            @unless ($product->is_active)<x-admin.badge color="muted">{{ __('crm.leads.items.draft') }}</x-admin.badge>@endunless
                                        </button>
                                    @empty
                                        <p class="px-3 py-3 text-sm text-slate-500">{{ __('crm.leads.items.no_results') }}</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('crm.leads.sections.lead')">
                <div class="space-y-4">
                    <x-admin.select wire:model.live="form.type" :label="__('crm.leads.fields.type')" required :options="collect($this->typeOptions)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                    <x-admin.select wire:model="form.source" :label="__('crm.leads.fields.source')" required :options="collect($this->sourceOptions)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                    @if ($lead === null)
                        <x-admin.select wire:model="form.manager_id" :label="__('crm.leads.fields.manager')" :placeholder="__('crm.leads.unassigned')" :options="$this->managerOptions" />
                    @endif
                    <x-admin.money-input wire:model="form.estimated_amount" :label="__('crm.leads.fields.estimated_amount')" :hint="__('crm.leads.estimate_hint')" />
                </div>
            </x-admin.card>
        </aside>
    </div>

    <x-admin.sticky-save-bar :cancel-href="$backHref" />
</form>
