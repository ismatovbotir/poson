@php
    $tabs = collect(\App\Livewire\Admin\Repairs\Index::TABS)->mapWithKeys(fn ($tab) => [$tab => __('repairs.tabs.'.$tab)]);
    $statusOptions = collect(\App\Enums\RepairStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    $coverageOptions = collect(\App\Enums\RepairCoverage::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
@endphp

<div>
    <x-admin.page-header :title="__('repairs.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.service') }} <span class="text-slate-300">/</span> {{ __('repairs.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.repairs.create')" wire:navigate icon="plus">{{ __('repairs.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Tabs --}}
    <div class="scrollbar-none -mx-4 mt-5 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="{{ __('repairs.tabs_label') }}">
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
    <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_11rem_11rem_12rem_11rem]">
        <label class="relative min-w-0 sm:col-span-2 lg:col-span-1">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" maxlength="64" placeholder="{{ __('repairs.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <x-admin.select wire:model.live="status" :aria-label="__('repairs.fields.status')" :placeholder="__('repairs.filters.all_statuses')" :options="$statusOptions" />
        <x-admin.select wire:model.live="coverage" :aria-label="__('repairs.fields.coverage')" :placeholder="__('repairs.filters.all_coverages')" :options="$coverageOptions" />
        <x-admin.select wire:model.live="engineer" :aria-label="__('repairs.fields.assigned_to')" :placeholder="__('repairs.filters.engineer_all')" :options="['me' => __('repairs.filters.engineer_me'), 'none' => __('repairs.filters.engineer_none')] + $this->engineerOptions" />
        <x-admin.select wire:model.live="sort" :aria-label="__('repairs.sort.label')" :options="collect(array_keys(\App\Livewire\Admin\Repairs\Index::SORTS))->mapWithKeys(fn ($key) => [$key => __('repairs.sort.'.$key)])->all()" />
    </div>

    @if ($this->activeFilters !== [])
        <div class="mt-3 flex flex-wrap gap-2" data-filter-chips>
            @foreach ($this->activeFilters as $filter => $label)
                <span wire:key="chip-{{ $filter }}" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-1.5 text-xs font-medium text-slate-700">
                    {{ $label }}
                    <button type="button" wire:click="clearFilter('{{ $filter }}')" class="grid size-5 place-items-center rounded-full hover:bg-slate-100" aria-label="{{ __('repairs.filters.remove') }}: {{ $label }}"><x-admin.icon name="x" size="size-3.5" /></button>
                </span>
            @endforeach
            <button type="button" wire:click="clearFilters" class="px-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('repairs.filters.clear') }}</button>
        </div>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('repairs.no_results')" :body="__('repairs.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('repairs.filters.clear') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="tool" :title="__('repairs.empty_title')" :body="__('repairs.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.repairs.create')" wire:navigate icon="plus">{{ __('repairs.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('repairs.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('repairs.columns.repair') }}</th>
                    <th class="px-4 py-3">{{ __('repairs.columns.device') }}</th>
                    <th class="px-4 py-3">{{ __('repairs.columns.status') }}</th>
                    <th class="px-4 py-3">{{ __('repairs.columns.engineer') }}</th>
                    <th class="whitespace-nowrap px-4 py-3">{{ __('repairs.columns.received') }}</th>
                    <th class="w-28 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $repair)
                    <tr wire:key="repair-row-{{ $repair->id }}" class="hover:bg-slate-50/70" data-repair="{{ $repair->id }}">
                        <td class="max-w-[16rem] py-3 pl-5 pr-4">
                            <a href="{{ route('admin.repairs.show', $repair) }}" wire:navigate class="block truncate font-semibold hover:text-brand-700"><span class="font-mono">{{ $repair->number }}</span></a>
                            <span class="block truncate text-xs text-slate-500">{{ $repair->company ?: $repair->contact_name }} · {{ \App\Support\Phone::display($repair->contact_phone) }} <x-admin.foreign-phone :phone="$repair->contact_phone" /></span>
                        </td>
                        <td class="max-w-xs px-4 py-3 text-slate-600">
                            @include('livewire.admin.repairs.partials.device', ['repair' => $repair])
                        </td>
                        <td class="px-4 py-3"><span class="flex flex-wrap gap-1">@include('livewire.admin.repairs.partials.badges', ['repair' => $repair])</span></td>
                        <td class="max-w-[12rem] px-4 py-3">@include('livewire.admin.repairs.partials.engineer', ['repair' => $repair])</td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ \App\Support\DateFormat::iso($repair->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</td>
                        <td class="py-3 pr-5 text-right">
                            @if (in_array($repair->id, $takeable, true))
                                <x-admin.button size="sm" variant="soft" wire:click="take({{ $repair->id }})" data-take="{{ $repair->id }}">{{ __('repairs.actions.take') }}</x-admin.button>
                            @else
                                <a href="{{ route('admin.repairs.show', $repair) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('repairs.open') }}: {{ $repair->number }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($items as $repair)
                    <article wire:key="repair-card-{{ $repair->id }}" class="relative rounded-2xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('admin.repairs.show', $repair) }}" wire:navigate class="block truncate text-sm font-semibold"><span class="font-mono">{{ $repair->number }}</span> · {{ $repair->company ?: $repair->contact_name }}</a>
                                <p class="truncate text-xs text-slate-500">{{ \App\Support\Phone::display($repair->contact_phone) }} · {{ \App\Support\DateFormat::iso($repair->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</p>
                            </div>
                            @include('livewire.admin.repairs.partials.engineer', ['repair' => $repair])
                        </div>
                        <p class="mt-1.5 line-clamp-2 text-sm text-slate-600">@include('livewire.admin.repairs.partials.device', ['repair' => $repair])</p>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3">
                            @include('livewire.admin.repairs.partials.badges', ['repair' => $repair])
                            @if (in_array($repair->id, $takeable, true))
                                <x-admin.button size="sm" variant="soft" class="ml-auto" wire:click="take({{ $repair->id }})">{{ __('repairs.actions.take') }}</x-admin.button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
