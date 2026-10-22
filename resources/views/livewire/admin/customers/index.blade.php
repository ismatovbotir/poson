<div x-data="{ filters: false }">
    <x-admin.page-header :title="__('crm.customers.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.sales') }} <span class="text-slate-300">/</span> {{ __('crm.customers.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.customers.create')" wire:navigate icon="plus">{{ __('crm.customers.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Toolbar --}}
    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('crm.customers.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <div class="flex rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="{{ __('crm.customers.fields.manager') }}">
            @foreach (['' => __('crm.customers.filters.manager_all'), 'me' => __('crm.customers.filters.manager_me'), 'none' => __('crm.customers.filters.manager_none')] as $value => $label)
                <button type="button" wire:click="$set('manager', '{{ $value }}')" aria-pressed="{{ $manager === $value ? 'true' : 'false' }}" data-scope="{{ $value ?: 'all' }}" @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-slate-900 text-white' => $manager === $value, 'text-slate-600 hover:text-slate-900' => $manager !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" x-on:click="filters = true" class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none lg:h-10">
            <x-admin.icon name="filter" size="size-4" />{{ __('crm.customers.filters.open') }}
            @if (count($this->activeFilters) > 0)
                <span class="rounded-full bg-brand-600 px-1.5 text-[11px] font-bold text-white">{{ count($this->activeFilters) }}</span>
            @endif
        </button>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('crm.customers.sort.label') }}</span>
            <x-admin.icon name="sort" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <select wire:model.live="sort" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-9 pr-8 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                @foreach (array_keys(\App\Livewire\Admin\Customers\Index::SORTS) as $sortKey)
                    <option value="{{ $sortKey }}">{{ __('crm.customers.sort.'.$sortKey) }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    @if ($this->activeFilters !== [])
        <div class="mt-3 flex flex-wrap gap-2" data-filter-chips>
            @foreach ($this->activeFilters as $filter => $label)
                <span wire:key="chip-{{ $filter }}" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-1.5 text-xs font-medium text-slate-700">
                    {{ $label }}
                    <button type="button" wire:click="clearFilter('{{ $filter }}')" class="grid size-5 place-items-center rounded-full hover:bg-slate-100" aria-label="{{ __('crm.customers.filters.remove') }}: {{ $label }}"><x-admin.icon name="x" size="size-3.5" /></button>
                </span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="px-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('crm.customers.filters.clear') }}</button>
        </div>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('crm.customers.no_results')" :body="__('crm.customers.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('crm.customers.filters.clear') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="building" :title="__('crm.customers.empty_title')" :body="__('crm.customers.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.customers.create')" wire:navigate icon="plus">{{ __('crm.customers.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('crm.customers.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('crm.customers.columns.customer') }}</th>
                    <th class="px-4 py-3">{{ __('crm.customers.columns.contact') }}</th>
                    <th class="px-4 py-3">{{ __('crm.customers.columns.price_level') }}</th>
                    <th class="px-4 py-3">{{ __('crm.customers.columns.manager') }}</th>
                    <th class="w-16 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $customer)
                    <tr wire:key="customer-row-{{ $customer->id }}" class="hover:bg-slate-50/70" data-customer="{{ $customer->id }}">
                        <td class="max-w-[18rem] py-3 pl-5 pr-4">
                            <a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="block truncate font-semibold hover:text-brand-700">{{ $customer->name }}</a>
                            <span class="block truncate text-xs text-slate-500">{{ $customer->type->label() }}@if ($customer->inn) · {{ __('crm.customers.fields.inn') }} {{ $customer->inn }}@endif</span>
                        </td>
                        <td class="max-w-[16rem] px-4 py-3 text-slate-600">
                            @if ($customer->primaryContact)
                                <span class="block truncate">{{ $customer->primaryContact->name }}</span>
                            @endif
                            <span class="block truncate text-xs text-slate-500">{{ $customer->phone ? \App\Support\Phone::display($customer->phone) : ($customer->primaryContact?->phone ? \App\Support\Phone::display($customer->primaryContact->phone) : __('admin.common.empty_value')) }}</span>
                        </td>
                        <td class="px-4 py-3"><x-admin.badge :color="$customer->price_level->color()">{{ $customer->price_level->label() }}</x-admin.badge></td>
                        <td class="max-w-[12rem] px-4 py-3">@include('livewire.admin.customers.partials.manager', ['customer' => $customer])</td>
                        <td class="py-3 pr-5 text-right">
                            <a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('crm.customers.open') }}: {{ $customer->name }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($items as $customer)
                    <article wire:key="customer-card-{{ $customer->id }}" class="relative rounded-2xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="block truncate text-sm font-semibold">{{ $customer->name }}</a>
                                <p class="truncate text-xs text-slate-500">{{ $customer->type->label() }}@if ($customer->inn) · {{ $customer->inn }}@endif</p>
                            </div>
                            <x-admin.badge :color="$customer->price_level->color()">{{ $customer->price_level->label() }}</x-admin.badge>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-sm">
                            <span class="truncate text-slate-600">{{ $customer->primaryContact?->name ?? ($customer->phone ? \App\Support\Phone::display($customer->phone) : '') }}</span>
                            @include('livewire.admin.customers.partials.manager', ['customer' => $customer])
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>

    {{-- Filters --}}
    <div x-cloak x-show="filters" class="fixed inset-0 z-[55]" role="dialog" aria-modal="true" aria-labelledby="customer-filters-title" x-on:keydown.escape.window="filters = false">
        <div x-show="filters" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="filters = false"></div>
        <div x-show="filters" x-trap.noscroll="filters" class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl bg-white shadow-2xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-[26rem] sm:rounded-none">
            <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-slate-200 sm:hidden"></div>
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 id="customer-filters-title" class="text-base font-semibold">{{ __('crm.customers.filters.title') }}</h2>
                <button type="button" x-on:click="filters = false" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" /></button>
            </div>
            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                <x-admin.select wire:model.live="manager" :label="__('crm.customers.fields.manager')" :placeholder="__('crm.customers.filters.manager_all')" :options="['me' => __('crm.customers.filters.manager_me'), 'none' => __('crm.customers.filters.manager_none')] + $this->managerOptions" />
                <x-admin.select wire:model.live="type" :label="__('crm.customers.fields.type')" :placeholder="__('crm.customers.filters.all_types')" :options="collect(\App\Enums\CustomerType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                <x-admin.select wire:model.live="priceLevel" :label="__('crm.customers.fields.price_level')" :placeholder="__('crm.customers.filters.all_levels')" :options="collect(\App\Enums\PriceLevel::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
            </div>
            <div class="flex gap-2 border-t border-slate-100 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                <button type="button" wire:click="clearFilters" class="h-11 flex-1 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('crm.customers.filters.clear') }}</button>
                <button type="button" x-on:click="filters = false" class="h-11 flex-[2] rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">{{ __('crm.customers.filters.show', ['count' => number_format($items->total(), 0, ',', ' ')]) }}</button>
            </div>
        </div>
    </div>
</div>
