@php
    $canCreate = auth()->user()->can('create', \App\Models\NewsPost::class);
    $canDelete = auth()->user()->can('news.delete');
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
@endphp

<div>
    <x-admin.page-header :title="$kind->adminText('title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.content') }} <span class="text-slate-300">/</span> {{ $kind->adminText('title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route($kind->adminRoute('create'))" wire:navigate icon="plus">{{ $kind->adminText('create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ $kind->adminText('search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="{{ $selectClass }} pl-3">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                @foreach (\App\Enums\NewsState::cases() as $state)
                    <option value="{{ $state->value }}">{{ $state->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.news.sort.label') }}</span>
            <x-admin.icon name="sort" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <select wire:model.live="sort" class="{{ $selectClass }} pl-9">
                @foreach (array_keys(\App\Livewire\Admin\News\Index::SORTS) as $sortKey)
                    <option value="{{ $sortKey }}">{{ __('admin.news.sort.'.$sortKey) }}</option>
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
                <x-admin.empty-state icon="news" :title="$kind->adminText('empty_title')" :body="$kind->adminText('empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route($kind->adminRoute('create'))" wire:navigate icon="plus">{{ $kind->adminText('create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ $kind->adminText('title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('admin.news.columns.post') }}</th>
                    <th class="px-4 py-3">{{ __('admin.news.fields.published_at') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $post)
                    <tr wire:key="news-row-{{ $post->id }}" class="hover:bg-slate-50/70" data-news-row="{{ $post->slug }}">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route($kind->adminRoute('edit'), $post) }}" wire:navigate class="block min-w-0">
                                <span class="block font-semibold text-slate-900 hover:text-brand-700">{{ $post->title }}</span>
                                <span class="block font-mono text-xs text-slate-500">/{{ $kind->segment() }}/{{ $post->slug }}</span>
                            </a>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap tabular-nums text-slate-600">{{ $post->localPublishedAt()?->format('d.m.Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="flex flex-wrap gap-1.5">@include('livewire.admin.news.partials.state', ['post' => $post])</span></td>
                        <td class="py-3 pr-5">@include('livewire.admin.news.partials.actions', ['post' => $post])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $post)
                    <article wire:key="news-card-{{ $post->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route($kind->adminRoute('edit'), $post) }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $post->title }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ $post->localPublishedAt()?->format('d.m.Y H:i') ?? '—' }}</span>
                            <span class="mt-1 flex flex-wrap gap-1.5">@include('livewire.admin.news.partials.state', ['post' => $post])</span>
                        </a>
                        @include('livewire.admin.news.partials.actions', ['post' => $post])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
