@php
    $canCreate = auth()->user()->can('create', \App\Models\Category::class);
    $canUpdate = auth()->user()->can('update', \App\Models\Category::class);
    $canDelete = auth()->user()->can('delete', \App\Models\Category::class);
@endphp

<div>
    <x-admin.page-header :title="__('admin.categories.title')" :subtitle="__('admin.categories.subtitle', ['count' => $total])">
        <x-slot:breadcrumbs>{{ __('admin.nav.groups.catalog') }} <span class="text-slate-300">/</span> {{ __('admin.categories.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.categories.create')" wire:navigate icon="plus">{{ __('admin.categories.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Toolbar --}}
    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.categories.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
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

        @if ($results !== null)
            {{-- Search / status filter: flat list with the path --}}
            @if ($results->isEmpty())
                <x-admin.empty-state icon="search" :title="__('admin.catalog.no_results')" :body="__('admin.catalog.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('admin.catalog.clear_filters') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <ul class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-category-results>
                    @foreach ($results as $category)
                        <li wire:key="result-{{ $category->id }}">
                            @include('livewire.admin.categories.partials.row', ['category' => $category, 'hasChildren' => false, 'sortable' => false, 'path' => \App\Support\Catalog\CategoryTree::path($category->id)])
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($tree->isEmpty())
            <x-admin.empty-state icon="folder" :title="__('admin.categories.empty_title')" :body="__('admin.categories.empty_body')">
                @if ($canCreate)
                    <x-admin.button :href="route('admin.categories.create')" wire:navigate icon="plus">{{ __('admin.categories.create') }}</x-admin.button>
                @endif
            </x-admin.empty-state>
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-category-tree>
                <div class="hidden items-center gap-3 border-b border-slate-200 bg-slate-50/70 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:flex">
                    <span class="flex-1 pl-16">{{ __('admin.categories.columns.name') }}</span>
                    <span class="w-24 text-center">{{ __('admin.categories.columns.attributes') }}</span>
                    <span class="w-24 text-center">{{ __('admin.categories.columns.products') }}</span>
                    <span class="w-24">{{ __('admin.catalog.status') }}</span>
                    <span class="w-20"><span class="sr-only">{{ __('admin.common.actions') }}</span></span>
                </div>
                @include('livewire.admin.categories.partials.tree', ['nodes' => $tree->get('', collect()), 'depth' => 1])
            </div>
            @if ($canUpdate)
                <p class="mt-3 text-xs text-slate-500">{{ __('admin.categories.sort_hint') }}</p>
            @endif
        @endif
    </div>
</div>
