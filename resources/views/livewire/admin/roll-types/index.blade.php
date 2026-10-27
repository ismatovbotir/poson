@php
    $canCreate = auth()->user()->can('create', \App\Models\RollType::class);
    $canDelete = auth()->user()->can('delete', \App\Models\RollType::class);
@endphp

<div>
    <x-admin.page-header :title="__('admin.roll_types.title')">
        <x-slot:breadcrumbs>{{ __('admin.nav.groups.catalog') }} <span class="text-slate-300">/</span> {{ __('admin.roll_types.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.roll-types.create')" wire:navigate icon="plus">{{ __('admin.roll_types.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <p class="mt-2 max-w-3xl text-sm text-slate-500">{{ __('admin.roll_types.intro') }}</p>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                <option value="active">{{ __('admin.catalog.status_active') }}</option>
                <option value="inactive">{{ __('admin.catalog.status_inactive') }}</option>
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('admin.catalog.no_results')" :body="__('admin.catalog.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('admin.catalog.clear_filters') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="box" :title="__('admin.roll_types.empty_title')" :body="__('admin.roll_types.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.roll-types.create')" wire:navigate icon="plus">{{ __('admin.roll_types.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('admin.roll_types.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('admin.roll_types.columns.roll') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.roll_types.fields.labels_per_roll') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.roll_types.fields.core_diameter_mm') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.roll_types.columns.products') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.catalog.sort') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $rollType)
                    <tr wire:key="roll-type-row-{{ $rollType->id }}" class="hover:bg-slate-50/70" data-roll-type="{{ $rollType->id }}">
                        <td class="py-3 pl-5 pr-4"><a href="{{ route('admin.roll-types.edit', $rollType) }}" wire:navigate class="font-semibold text-slate-900 hover:text-brand-700">{{ $rollType->label() }}</a></td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ \App\Support\NumberFormat::format($rollType->labels_per_roll) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ \App\Support\NumberFormat::format($rollType->core_diameter_mm) }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ $rollType->products_count }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ $rollType->sort }}</td>
                        <td class="px-4 py-3">@include('livewire.admin.roll-types.partials.status', ['rollType' => $rollType])</td>
                        <td class="py-3 pr-5">@include('livewire.admin.roll-types.partials.actions', ['rollType' => $rollType])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $rollType)
                    <article wire:key="roll-type-card-{{ $rollType->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.roll-types.edit', $rollType) }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $rollType->label() }}</span>
                            <span class="mt-1 flex items-center gap-2 text-xs text-slate-500">@include('livewire.admin.roll-types.partials.status', ['rollType' => $rollType]) {{ trans_choice('admin.roll_types.products_count', $rollType->products_count, ['count' => $rollType->products_count]) }}</span>
                        </a>
                        @include('livewire.admin.roll-types.partials.actions', ['rollType' => $rollType])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
