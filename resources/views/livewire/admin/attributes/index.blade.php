@php
    $canCreate = auth()->user()->can('create', \App\Models\Attribute::class);
    $canDelete = auth()->user()->can('delete', \App\Models\Attribute::class);
@endphp

<div>
    <x-admin.page-header :title="__('admin.attributes.title')" :subtitle="__('admin.attributes.subtitle')">
        <x-slot:breadcrumbs>{{ __('admin.nav.groups.catalog') }} <span class="text-slate-300">/</span> {{ __('admin.attributes.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.attributes.create')" wire:navigate icon="plus">{{ __('admin.attributes.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.attributes.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.attributes.fields.type') }}</span>
            <select wire:model.live="type" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __('admin.attributes.all_types') }}</option>
                @foreach ($types as $typeCase)
                    <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                @endforeach
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
                <x-admin.empty-state icon="sliders" :title="__('admin.attributes.empty_title')" :body="__('admin.attributes.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.attributes.create')" wire:navigate icon="plus">{{ __('admin.attributes.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('admin.attributes.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('admin.attributes.columns.attribute') }}</th>
                    <th class="px-4 py-3">{{ __('admin.attributes.fields.type') }}</th>
                    <th class="px-4 py-3">{{ __('admin.attributes.fields.unit') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.attributes.columns.options') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.attributes.columns.filter') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.attributes.columns.in_use') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $attribute)
                    <tr wire:key="attr-row-{{ $attribute->id }}" class="hover:bg-slate-50/70">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route('admin.attributes.edit', $attribute) }}" wire:navigate class="block">
                                <span class="block font-semibold text-slate-900 hover:text-brand-700">{{ $attribute->name }}</span>
                                <span class="block font-mono text-xs text-slate-500">{{ $attribute->code }}</span>
                            </a>
                        </td>
                        <td class="px-4 py-3"><x-admin.badge :color="$attribute->type->color()">{{ $attribute->type->label() }}</x-admin.badge></td>
                        <td class="px-4 py-3 text-slate-600">{{ $attribute->unitLabel() ?? '—' }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ $attribute->type->hasOptions() ? $attribute->options_count : '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if ($attribute->is_filterable)
                                <x-admin.icon name="check" size="size-4" class="inline text-brand-600" /><span class="sr-only">{{ __('admin.common.yes') }}</span>
                            @else
                                <span class="text-slate-300" aria-hidden="true">—</span><span class="sr-only">{{ __('admin.common.no') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ trans_choice('admin.attributes.in_categories', $attribute->categories_count, ['count' => $attribute->categories_count]) }}</td>
                        <td class="py-3 pr-5">
                            @include('livewire.admin.attributes.partials.actions', ['attribute' => $attribute])
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $attribute)
                    <article wire:key="attr-card-{{ $attribute->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-start gap-3">
                            <a href="{{ route('admin.attributes.edit', $attribute) }}" wire:navigate class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold leading-snug">{{ $attribute->name }}</span>
                                <span class="mt-0.5 block font-mono text-xs text-slate-500">{{ $attribute->code }}</span>
                            </a>
                            @include('livewire.admin.attributes.partials.actions', ['attribute' => $attribute])
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500">
                            <x-admin.badge :color="$attribute->type->color()">{{ $attribute->type->label() }}</x-admin.badge>
                            @if ($attribute->unitLabel())<span>{{ $attribute->unitLabel() }}</span>@endif
                            @if ($attribute->is_filterable)<x-admin.badge color="brand">{{ __('admin.attributes.columns.filter') }}</x-admin.badge>@endif
                            <span class="ml-auto">{{ trans_choice('admin.attributes.in_categories', $attribute->categories_count, ['count' => $attribute->categories_count]) }}</span>
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
