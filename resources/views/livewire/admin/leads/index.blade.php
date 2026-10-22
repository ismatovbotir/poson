@php
    $tabs = collect(\App\Livewire\Admin\Leads\Index::TABS)->mapWithKeys(fn ($tab) => [$tab => __('crm.leads.tabs.'.$tab)]);
    $tz = \App\Models\Lead::TIMEZONE;
@endphp

<div x-data="{ filters: false }">
    <x-admin.page-header :title="__('crm.leads.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.sales') }} <span class="text-slate-300">/</span> {{ __('crm.leads.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.leads.create')" wire:navigate icon="plus">{{ __('crm.leads.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Tabs --}}
    <div class="scrollbar-none -mx-4 mt-5 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="{{ __('crm.leads.tabs_label') }}">
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
                    <span>{{ $label }}</span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs tabular-nums',
                        'bg-brand-50 text-brand-700' => $currentTab === $tab,
                        'bg-slate-100 text-slate-500' => $currentTab !== $tab,
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
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('crm.leads.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <div class="flex rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="{{ __('crm.leads.fields.manager') }}">
            @foreach (['' => __('crm.leads.filters.manager_all'), 'me' => __('crm.leads.filters.manager_me'), 'none' => __('crm.leads.filters.manager_none')] as $value => $label)
                <button type="button" wire:click="$set('manager', '{{ $value }}')" aria-pressed="{{ $manager === $value ? 'true' : 'false' }}" data-scope="{{ $value ?: 'all' }}" @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-slate-900 text-white' => $manager === $value, 'text-slate-600 hover:text-slate-900' => $manager !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" x-on:click="filters = true" class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none lg:h-10">
            <x-admin.icon name="filter" size="size-4" />{{ __('crm.leads.filters.open') }}
            @if (count($this->activeFilters) > 0)
                <span class="rounded-full bg-brand-600 px-1.5 text-[11px] font-bold text-white">{{ count($this->activeFilters) }}</span>
            @endif
        </button>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('crm.leads.sort.label') }}</span>
            <x-admin.icon name="sort" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <select wire:model.live="sort" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-9 pr-8 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                @foreach (array_keys(\App\Livewire\Admin\Leads\Index::SORTS) as $sortKey)
                    <option value="{{ $sortKey }}">{{ __('crm.leads.sort.'.$sortKey) }}</option>
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
                    <button type="button" wire:click="clearFilter('{{ $filter }}')" class="grid size-5 place-items-center rounded-full hover:bg-slate-100" aria-label="{{ __('crm.leads.filters.remove') }}: {{ $label }}"><x-admin.icon name="x" size="size-3.5" /></button>
                </span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="px-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('crm.leads.filters.clear') }}</button>
        </div>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('crm.leads.no_results')" :body="__('crm.leads.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('crm.leads.filters.clear') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="inbox" :title="__('crm.leads.empty_title')" :body="__('crm.leads.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.leads.create')" wire:navigate icon="plus">{{ __('crm.leads.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('crm.leads.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('crm.leads.columns.client') }}</th>
                    <th class="px-4 py-3">{{ __('crm.leads.columns.need') }}</th>
                    <th class="px-4 py-3">{{ __('crm.leads.columns.status') }}</th>
                    <th class="hidden px-4 py-3 xl:table-cell">{{ __('crm.leads.columns.source') }}</th>
                    <th class="px-4 py-3">{{ __('crm.leads.columns.manager') }}</th>
                    <th class="whitespace-nowrap px-4 py-3">{{ __('crm.leads.columns.created') }}</th>
                    <th class="w-28 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $lead)
                    <tr wire:key="lead-row-{{ $lead->id }}" class="hover:bg-slate-50/70" data-lead="{{ $lead->id }}">
                        <td class="max-w-[16rem] py-3 pl-5 pr-4">
                            <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="block truncate font-semibold hover:text-brand-700">{{ $lead->company ?: $lead->name }}</a>
                            <span class="block truncate text-xs text-slate-500"><span class="font-mono">{{ $lead->reference() }}</span> · {{ $lead->company ? $lead->name : \App\Support\Phone::display($lead->phone) }}</span>
                        </td>
                        <td class="max-w-xs truncate px-4 py-3 text-slate-600">@include('livewire.admin.leads.partials.need', ['lead' => $lead])</td>
                        <td class="px-4 py-3"><span class="flex flex-wrap gap-1">@include('livewire.admin.leads.partials.badges', ['lead' => $lead])</span></td>
                        <td class="hidden whitespace-nowrap px-4 py-3 text-slate-600 xl:table-cell">{{ $lead->source->label() }}</td>
                        <td class="max-w-[12rem] px-4 py-3">@include('livewire.admin.leads.partials.manager', ['lead' => $lead])</td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ \App\Support\DateFormat::iso($lead->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</td>
                        <td class="py-3 pr-5 text-right">
                            @if (in_array($lead->id, $takeable, true))
                                <x-admin.button size="sm" variant="soft" wire:click="take({{ $lead->id }})" data-take="{{ $lead->id }}">{{ __('crm.leads.actions.take') }}</x-admin.button>
                            @else
                                <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('crm.leads.open') }}: {{ $lead->reference() }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($items as $lead)
                    <article wire:key="lead-card-{{ $lead->id }}" class="relative rounded-2xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="block truncate text-sm font-semibold">{{ $lead->company ?: $lead->name }}</a>
                                <p class="truncate text-xs text-slate-500"><span class="font-mono">{{ $lead->reference() }}</span> · {{ $lead->source->label() }} · {{ \App\Support\DateFormat::iso($lead->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</p>
                            </div>
                            @include('livewire.admin.leads.partials.manager', ['lead' => $lead])
                        </div>
                        <p class="mt-1.5 line-clamp-2 text-sm text-slate-600">@include('livewire.admin.leads.partials.need', ['lead' => $lead])</p>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3">
                            @include('livewire.admin.leads.partials.badges', ['lead' => $lead])
                            @if (in_array($lead->id, $takeable, true))
                                <x-admin.button size="sm" variant="soft" class="ml-auto" wire:click="take({{ $lead->id }})">{{ __('crm.leads.actions.take') }}</x-admin.button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>

    {{-- Filters: bottom sheet on phones, slide-over from sm --}}
    <div x-cloak x-show="filters" class="fixed inset-0 z-[55]" role="dialog" aria-modal="true" aria-labelledby="lead-filters-title" x-on:keydown.escape.window="filters = false">
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
                <h2 id="lead-filters-title" class="text-base font-semibold">{{ __('crm.leads.filters.title') }}</h2>
                <button type="button" x-on:click="filters = false" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" /></button>
            </div>
            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                <x-admin.select wire:model.live="status" :label="__('crm.leads.fields.status')" :placeholder="__('crm.leads.filters.all_statuses')" :options="collect(\App\Enums\LeadStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                <x-admin.select wire:model.live="type" :label="__('crm.leads.fields.type')" :placeholder="__('crm.leads.filters.all_types')" :options="collect($this->typeOptions)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                <x-admin.select wire:model.live="source" :label="__('crm.leads.fields.source')" :placeholder="__('crm.leads.filters.all_sources')" :options="collect(\App\Enums\LeadSource::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                <x-admin.select wire:model.live="manager" :label="__('crm.leads.fields.manager')" :placeholder="__('crm.leads.filters.manager_all')" :options="['me' => __('crm.leads.filters.manager_me'), 'none' => __('crm.leads.filters.manager_none')] + $this->managerOptions" />
                <div class="grid grid-cols-2 gap-3">
                    <x-admin.input wire:model.live="from" type="date" :label="__('crm.leads.filters.from')" />
                    <x-admin.input wire:model.live="to" type="date" :label="__('crm.leads.filters.to')" />
                </div>
                <x-admin.toggle wire:model.live="duplicates" :label="__('crm.leads.filters.duplicates')" :description="__('crm.leads.duplicate_hint', ['days' => \App\Models\Lead::DUPLICATE_WINDOW_DAYS])" />
            </div>
            <div class="flex gap-2 border-t border-slate-100 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                <button type="button" wire:click="clearFilters" class="h-11 flex-1 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('crm.leads.filters.clear') }}</button>
                <button type="button" x-on:click="filters = false" class="h-11 flex-[2] rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">{{ __('crm.leads.filters.show', ['count' => number_format($items->total(), 0, ',', ' ')]) }}</button>
            </div>
        </div>
    </div>
</div>
