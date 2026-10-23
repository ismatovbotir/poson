@php
    $canCreate = auth()->user()->can('create', \App\Models\Page::class);
    $canDelete = auth()->user()->can('pages.delete');
@endphp

<div>
    <x-admin.page-header :title="__('admin.pages.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.content') }} <span class="text-slate-300">/</span> {{ __('admin.pages.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.pages.create')" wire:navigate icon="plus">{{ __('admin.pages.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.pages.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                <option value="active">{{ __('admin.pages.active') }}</option>
                <option value="inactive">{{ __('admin.pages.inactive') }}</option>
                <option value="review">{{ __('admin.pages.needs_review') }}</option>
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
                <x-admin.empty-state icon="page" :title="__('admin.pages.empty_title')" :body="__('admin.pages.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.pages.create')" wire:navigate icon="plus">{{ __('admin.pages.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('admin.pages.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('admin.pages.columns.page') }}</th>
                    <th class="px-4 py-3">{{ __('admin.pages.fields.template') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('admin.catalog.sort') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $page)
                    <tr wire:key="page-row-{{ $page->id }}" class="hover:bg-slate-50/70" data-page-row="{{ $page->slug }}">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route('admin.pages.edit', $page) }}" wire:navigate class="block min-w-0">
                                <span class="block font-semibold text-slate-900 hover:text-brand-700">{{ $page->title }}</span>
                                <span class="block font-mono text-xs text-slate-500">/page/{{ $page->slug }}</span>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $page->template->label() }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ $page->sort }}</td>
                        <td class="px-4 py-3">@include('livewire.admin.pages.partials.badges', ['page' => $page])</td>
                        <td class="py-3 pr-5">@include('livewire.admin.pages.partials.actions', ['page' => $page])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $page)
                    <article wire:key="page-card-{{ $page->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.pages.edit', $page) }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $page->title }}</span>
                            <span class="block truncate font-mono text-xs text-slate-500">/page/{{ $page->slug }}</span>
                            <span class="mt-1 block">@include('livewire.admin.pages.partials.badges', ['page' => $page])</span>
                        </a>
                        @include('livewire.admin.pages.partials.actions', ['page' => $page])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
