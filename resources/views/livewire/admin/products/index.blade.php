@php
    $locales = config('app.supported_locales');
    $inTrash = $currentTab === 'trash';
    $selectable = ! $inTrash && $editable !== [];
    $tabs = collect(\App\Livewire\Admin\Products\Index::TABS)->mapWithKeys(fn ($tab) => [$tab => __('admin.products.tabs.'.$tab)]);
    $pageIds = array_map('strval', $items->getCollection()->filter(fn ($product) => in_array($product->id, $editable, true))->modelKeys());
@endphp

<div x-data="{ filters: false }">
    <x-admin.page-header :title="__('admin.products.title')">
        <x-slot:breadcrumbs>{{ __('admin.nav.groups.catalog') }} <span class="text-slate-300">/</span> {{ __('admin.products.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.products.create')" wire:navigate icon="plus">{{ __('admin.products.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Status tabs --}}
    <div class="scrollbar-none -mx-4 mt-5 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="{{ __('admin.products.tabs_label') }}">
        <div class="flex gap-6">
            @foreach ($tabs as $tab => $label)
                <button
                    type="button"
                    role="tab"
                    wire:click="setTab('{{ $tab }}')"
                    aria-selected="{{ $currentTab === $tab ? 'true' : 'false' }}"
                    data-tab="{{ $tab }}"
                    @class([
                        '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 pb-3 text-sm font-semibold',
                        'border-brand-600 text-slate-900' => $currentTab === $tab,
                        'border-transparent text-slate-500 hover:text-slate-700' => $currentTab !== $tab,
                    ])
                >
                    @if ($tab === 'trash')<x-admin.icon name="trash" size="size-4" />@endif
                    <span>{{ $label }}</span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs tabular-nums',
                        'bg-brand-50 text-brand-700' => $currentTab === $tab && $tab !== 'incomplete',
                        'bg-amber-50 text-amber-700' => $tab === 'incomplete' && $this->tabCounts[$tab] > 0,
                        'bg-slate-100 text-slate-500' => $currentTab !== $tab && ! ($tab === 'incomplete' && $this->tabCounts[$tab] > 0),
                    ])>{{ number_format($this->tabCounts[$tab], 0, ',', ' ') }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.products.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <button type="button" x-on:click="filters = true" class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none lg:h-10">
            <x-admin.icon name="filter" size="size-4" />{{ __('admin.products.filters.open') }}
            @if (count($this->activeFilters) > 0)
                <span class="rounded-full bg-brand-600 px-1.5 text-[11px] font-bold text-white">{{ count($this->activeFilters) }}</span>
            @endif
        </button>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.products.sort.label') }}</span>
            <x-admin.icon name="sort" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <select wire:model.live="sort" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-9 pr-8 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                @foreach (array_keys(\App\Livewire\Admin\Products\Index::SORTS) as $sortKey)
                    <option value="{{ $sortKey }}">{{ __('admin.products.sort.'.$sortKey) }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    {{-- Active filter chips --}}
    @if ($this->activeFilters !== [])
        <div class="mt-3 flex flex-wrap gap-2" data-filter-chips>
            @foreach ($this->activeFilters as $filter => $label)
                <span wire:key="chip-{{ $filter }}" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-1.5 text-xs font-medium text-slate-700">
                    {{ $label }}
                    <button type="button" wire:click="clearFilter('{{ $filter }}')" class="grid size-5 place-items-center rounded-full hover:bg-slate-100" aria-label="{{ __('admin.products.filters.remove') }}: {{ $label }}"><x-admin.icon name="x" size="size-3.5" /></button>
                </span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="px-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('admin.products.filters.clear') }}</button>
        </div>
    @endif

    {{-- Bulk action bar --}}
    @if ($selectable)
        <div
            x-cloak
            x-show="$wire.selected.length"
            x-transition
            class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-2 rounded-xl bg-slate-900 p-2 pl-4 text-white shadow-2xl lg:sticky lg:inset-x-auto lg:bottom-auto lg:top-20 lg:mt-4"
            data-bulk-bar
        >
            <span class="text-sm font-semibold" x-text="@js(__('admin.products.bulk.selected')).replace(':count', $wire.selected.length)"></span>
            <div class="ml-auto flex items-center gap-1">
                @if ($currentTab !== 'active')
                    <button type="button" wire:click="bulkActivate" class="h-9 rounded-lg px-3 text-sm font-semibold hover:bg-white/10">{{ __('admin.products.bulk.activate') }}</button>
                @endif
                @if ($currentTab !== 'drafts')
                    <button type="button" wire:click="bulkDeactivate" class="h-9 rounded-lg px-3 text-sm font-semibold hover:bg-white/10">{{ __('admin.products.bulk.deactivate') }}</button>
                @endif
                <button
                    type="button"
                    x-on:click="$dispatch('confirm', {
                        title: @js(__('admin.products.bulk.delete_title')),
                        body: @js(__('admin.products.bulk.delete_body')),
                        label: @js(__('admin.products.bulk.delete')),
                        component: $wire.$id, method: 'bulkDelete',
                    })"
                    class="h-9 rounded-lg px-3 text-sm font-semibold text-red-300 hover:bg-white/10"
                >{{ __('admin.products.bulk.delete') }}</button>
                <button type="button" x-on:click="$wire.selected = []" class="grid size-9 place-items-center rounded-lg hover:bg-white/10" aria-label="{{ __('admin.products.bulk.clear') }}"><x-admin.icon name="x" size="size-4" /></button>
            </div>
        </div>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('admin.catalog.no_results')" :body="__('admin.catalog.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('admin.catalog.clear_filters') }}</x-admin.button>
                </x-admin.empty-state>
            @elseif ($inTrash)
                <x-admin.empty-state icon="trash" :title="__('admin.products.trash_empty_title')" :body="__('admin.products.trash_empty_body')" />
            @else
                <x-admin.empty-state icon="box" :title="__('admin.products.empty_title')" :body="__('admin.products.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.products.create')" wire:navigate icon="plus">{{ __('admin.products.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('admin.products.title') }}</x-slot:caption>
                <x-slot:head>
                    @if ($selectable)
                        <th class="w-12 py-3 pl-5">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-slate-300 accent-brand-600"
                                x-bind:checked="@js($pageIds).length > 0 && @js($pageIds).every(id => $wire.selected.map(String).includes(id))"
                                x-on:change="$wire.selected = $event.target.checked ? @js($pageIds) : []"
                                aria-label="{{ __('admin.products.bulk.select_all') }}"
                            >
                        </th>
                    @endif
                    <th @class(['py-3 pr-4', 'pl-3' => $selectable, 'pl-5' => ! $selectable])>{{ __('admin.products.columns.product') }}</th>
                    <th class="hidden px-4 py-3 xl:table-cell">{{ __('admin.products.columns.category') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">{{ __('admin.products.columns.price') }}</th>
                    <th class="px-4 py-3">{{ __('admin.products.columns.stock') }}</th>
                    <th class="hidden px-4 py-3 xl:table-cell">{{ __('admin.products.columns.ordering') }}</th>
                    <th class="px-4 py-3">{{ __('admin.products.columns.languages') }}</th>
                    <th class="px-4 py-3">{{ __('admin.products.columns.status') }}</th>
                    <th class="hidden px-4 py-3 2xl:table-cell">{{ $inTrash ? __('admin.products.columns.deleted') : __('admin.products.columns.updated') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $product)
                    @php($canEdit = in_array($product->id, $editable, true))
                    <tr wire:key="product-row-{{ $product->id }}" x-bind:class="$wire.selected.map(String).includes('{{ $product->id }}') ? 'bg-brand-50/50' : 'hover:bg-slate-50/70'" data-product="{{ $product->sku }}">
                        @if ($selectable)
                            <td class="py-3 pl-5">
                                @if ($canEdit)
                                    <input type="checkbox" value="{{ $product->id }}" wire:model="selected" class="size-4 rounded border-slate-300 accent-brand-600" aria-label="{{ __('admin.products.bulk.select') }}: {{ $product->name }}">
                                @endif
                            </td>
                        @endif
                        <td @class(['py-3 pr-4', 'pl-3' => $selectable, 'pl-5' => ! $selectable])>
                            @include('livewire.admin.products.partials.name', ['product' => $product, 'linked' => ! $inTrash])
                        </td>
                        <td class="hidden max-w-[14rem] truncate px-4 py-3 text-slate-600 xl:table-cell">{{ \App\Support\Catalog\CategoryTree::name($product->category_id) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">@include('livewire.admin.products.partials.price', ['product' => $product])</td>
                        <td class="whitespace-nowrap px-4 py-3"><x-admin.badge :color="$product->stock_status->color()">{{ $product->stock_status->label() }}</x-admin.badge></td>
                        <td class="hidden whitespace-nowrap px-4 py-3 text-slate-600 xl:table-cell">{{ $product->effectiveSaleMode()->label() }}</td>
                        <td class="px-4 py-3">@include('livewire.admin.products.partials.languages', ['product' => $product])</td>
                        <td class="px-4 py-3">@include('livewire.admin.products.partials.status', ['product' => $product])</td>
                        <td class="hidden whitespace-nowrap px-4 py-3 text-slate-500 2xl:table-cell">{{ ($inTrash ? $product->deleted_at : $product->updated_at)?->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') }}</td>
                        <td class="py-3 pr-5">@include('livewire.admin.products.partials.actions', ['product' => $product])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @if ($selectable && $pageIds !== [])
                    <label class="flex items-center gap-3 px-1 text-sm font-medium text-slate-600">
                        <input
                            type="checkbox"
                            class="size-5 rounded border-slate-300 accent-brand-600"
                            x-bind:checked="@js($pageIds).every(id => $wire.selected.map(String).includes(id))"
                            x-on:change="$wire.selected = $event.target.checked ? @js($pageIds) : []"
                        >{{ __('admin.products.bulk.select_all') }}
                    </label>
                @endif
                @foreach ($items as $product)
                    @php($canEdit = in_array($product->id, $editable, true))
                    <article wire:key="product-card-{{ $product->id }}" x-bind:class="$wire.selected.map(String).includes('{{ $product->id }}') ? 'border-brand-300 ring-2 ring-brand-500/20' : 'border-slate-200'" class="relative rounded-2xl border bg-white p-3.5">
                        <div class="flex gap-3">
                            @if ($selectable && $canEdit)
                                <input type="checkbox" value="{{ $product->id }}" wire:model="selected" class="mt-1 size-5 shrink-0 rounded border-slate-300 accent-brand-600" aria-label="{{ __('admin.products.bulk.select') }}: {{ $product->name }}">
                            @endif
                            @include('livewire.admin.products.partials.thumb', ['product' => $product, 'size' => 'size-16'])
                            <div class="min-w-0 flex-1">
                                @if (! $inTrash)
                                    <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="line-clamp-2 text-sm font-semibold leading-snug">{{ $product->name }}</a>
                                @else
                                    <span class="line-clamp-2 text-sm font-semibold leading-snug">{{ $product->name }}</span>
                                @endif
                                <span class="mt-0.5 block truncate text-xs text-slate-500"><span class="font-mono">{{ $product->sku }}</span> · {{ \App\Support\Catalog\CategoryTree::name($product->category_id) }}</span>
                                <span class="mt-1.5 block text-base font-bold tabular-nums">@include('livewire.admin.products.partials.price', ['product' => $product])</span>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3">
                            @include('livewire.admin.products.partials.status', ['product' => $product])
                            <x-admin.badge :color="$product->stock_status->color()">{{ $product->stock_status->label() }}</x-admin.badge>
                            <span class="ml-auto">@include('livewire.admin.products.partials.languages', ['product' => $product])</span>
                            @include('livewire.admin.products.partials.actions', ['product' => $product])
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>

    {{-- Filters: bottom sheet on phones, slide-over from sm --}}
    <div x-cloak x-show="filters" class="fixed inset-0 z-[55]" role="dialog" aria-modal="true" aria-labelledby="product-filters-title" x-on:keydown.escape.window="filters = false">
        <div x-show="filters" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="filters = false"></div>
        <div
            x-show="filters"
            x-trap.noscroll="filters"
            x-transition:enter="transition duration-200 ease-out"
            x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full"
            x-transition:enter-end="translate-y-0 sm:translate-x-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="translate-y-0 sm:translate-x-0"
            x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full"
            class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl bg-white shadow-2xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-[26rem] sm:rounded-none"
        >
            <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-slate-200 sm:hidden"></div>
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 id="product-filters-title" class="text-base font-semibold">{{ __('admin.products.filters.title') }}</h2>
                <button type="button" x-on:click="filters = false" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" /></button>
            </div>
            <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
                <fieldset>
                    <legend class="text-sm font-semibold">{{ __('admin.products.fields.type') }}</legend>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" value="" wire:model.live="type" class="peer sr-only">
                            <span class="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ __('admin.products.filters.all_types') }}</span>
                        </label>
                        @foreach (\App\Enums\ProductType::cases() as $productType)
                            <label class="cursor-pointer">
                                <input type="radio" value="{{ $productType->value }}" wire:model.live="type" class="peer sr-only">
                                <span class="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $productType->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div>
                    <x-admin.select wire:model.live="category" :label="__('admin.products.fields.category')" :placeholder="__('admin.products.filters.all_categories')" :options="$this->categoryOptions" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.products.filters.category_hint') }}</p>
                </div>
                <x-admin.select wire:model.live="brand" :label="__('admin.products.fields.brand')" :placeholder="__('admin.products.filters.all_brands')" :options="$this->brandOptions" />
                <fieldset>
                    <legend class="text-sm font-semibold">{{ __('admin.products.fields.stock_status') }}</legend>
                    <div class="mt-2.5 space-y-2 text-sm">
                        <label class="flex items-center gap-3"><input type="radio" value="" wire:model.live="stock" class="size-5 accent-brand-600">{{ __('admin.products.filters.all_stock') }}</label>
                        @foreach (\App\Enums\StockStatus::cases() as $stockStatus)
                            <label class="flex items-center gap-3"><input type="radio" value="{{ $stockStatus->value }}" wire:model.live="stock" class="size-5 accent-brand-600">{{ $stockStatus->label() }}</label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="flex gap-2 border-t border-slate-100 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                <button type="button" wire:click="clearFilters" class="h-11 flex-1 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('admin.products.filters.clear') }}</button>
                <button type="button" x-on:click="filters = false" class="h-11 flex-[2] rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">{{ __('admin.products.filters.show', ['count' => number_format($items->total(), 0, ',', ' ')]) }}</button>
            </div>
        </div>
    </div>
</div>
