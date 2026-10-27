@php
    $canCreate = auth()->user()->can('create', \App\Models\Banner::class);
    $canUpdate = auth()->user()->can('banners.update');
    $canDelete = auth()->user()->can('banners.delete');
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
@endphp

<div>
    <x-admin.page-header :title="__('admin.banners.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.content') }} <span class="text-slate-300">/</span> {{ __('admin.banners.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.banners.create')" wire:navigate icon="plus">{{ __('admin.banners.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.banners.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.banners.fields.placement') }}</span>
            <select wire:model.live="placement" class="{{ $selectClass }}">
                <option value="">{{ __('admin.banners.placement_all') }}</option>
                @foreach (\App\Enums\BannerPlacement::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        @if ($placement === \App\Enums\BannerPlacement::Category->value)
            <label class="relative flex-1 sm:flex-none">
                <span class="sr-only">{{ __('admin.banners.fields.category') }}</span>
                <select wire:model.live="categoryId" class="{{ $selectClass }} sm:max-w-64" data-banner-category-filter>
                    <option value="">{{ __('admin.banners.category_all') }}</option>
                    @foreach ($this->categoryOptions as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
                <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
            </label>
        @endif
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="{{ $selectClass }}">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                @foreach (\App\Enums\BannerState::cases() as $state)
                    <option value="{{ $state->value }}">{{ $state->label() }}</option>
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
                <x-admin.empty-state icon="image" :title="__('admin.banners.empty_title')" :body="__('admin.banners.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.banners.create')" wire:navigate icon="plus">{{ __('admin.banners.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('admin.banners.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('admin.banners.columns.banner') }}</th>
                    <th class="px-4 py-3">{{ __('admin.banners.fields.placement') }}</th>
                    <th class="px-4 py-3">{{ __('admin.banners.columns.dates') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    @if ($canUpdate)
                        <th class="px-4 py-3 text-center">{{ __('admin.catalog.sort') }}</th>
                    @endif
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $banner)
                    <tr wire:key="banner-row-{{ $banner->id }}" class="hover:bg-slate-50/70" data-banner-row="{{ $banner->id }}">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route('admin.banners.edit', $banner) }}" wire:navigate class="flex min-w-0 items-center gap-3">
                                @include('livewire.admin.banners.partials.thumb', ['banner' => $banner])
                                <span class="min-w-0 font-semibold text-slate-900 hover:text-brand-700">{{ $banner->title }}</span>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $banner->placement->label() }}
                            @if ($banner->category_id)
                                <span class="block text-xs text-slate-500">{{ \App\Support\Catalog\CategoryTree::path($banner->category_id) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600">@include('livewire.admin.banners.partials.dates', ['banner' => $banner])</td>
                        <td class="px-4 py-3">@include('livewire.admin.banners.partials.state', ['banner' => $banner])</td>
                        @if ($canUpdate)
                            <td class="px-4 py-3">@include('livewire.admin.banners.partials.move', ['banner' => $banner])</td>
                        @endif
                        <td class="py-3 pr-5">@include('livewire.admin.banners.partials.actions', ['banner' => $banner, 'canDelete' => $canDelete])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $banner)
                    <article wire:key="banner-card-{{ $banner->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.banners.edit', $banner) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                            @include('livewire.admin.banners.partials.thumb', ['banner' => $banner])
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold">{{ $banner->title }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $banner->placement->label() }}@if ($banner->category_id) · {{ \App\Support\Catalog\CategoryTree::name($banner->category_id) }}@endif</span>
                                <span class="mt-1 block">@include('livewire.admin.banners.partials.state', ['banner' => $banner])</span>
                            </span>
                        </a>
                        @if ($canUpdate)
                            @include('livewire.admin.banners.partials.move', ['banner' => $banner])
                        @endif
                        @include('livewire.admin.banners.partials.actions', ['banner' => $banner, 'canDelete' => $canDelete])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
