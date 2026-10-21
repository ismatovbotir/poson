{{-- Category row (tree node or search result). --}}
@php
    $first ??= true;
    $last ??= true;
    $depth ??= 1;
@endphp
<div class="flex items-center gap-2 px-3 py-2.5 sm:gap-3 sm:px-4">
    @if ($sortable)
        <span wire:sort:handle class="hidden cursor-grab text-slate-400 hover:text-slate-600 lg:block" title="{{ __('admin.categories.drag') }}" aria-hidden="true"><x-admin.icon name="grip" /></span>
        <span class="flex flex-col lg:hidden">
            <button type="button" wire:click="move({{ $category->id }}, -1)" @disabled($first) class="grid size-6 place-items-center rounded text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_up') }}: {{ $category->name }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
            <button type="button" wire:click="move({{ $category->id }}, 1)" @disabled($last) class="grid size-6 place-items-center rounded text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_down') }}: {{ $category->name }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
        </span>
    @endif

    @if ($hasChildren)
        <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" class="grid size-7 shrink-0 place-items-center rounded-md text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.categories.toggle_children') }}: {{ $category->name }}">
            <x-admin.icon name="chevron-right" size="size-4" class="transition-transform" x-bind:class="open && 'rotate-90'" />
        </button>
    @else
        <span class="size-7 shrink-0" aria-hidden="true"></span>
    @endif

    <span @class(['grid size-9 shrink-0 place-items-center rounded-lg', 'bg-brand-50 text-brand-600' => $depth === 1, 'bg-slate-100 text-slate-500' => $depth > 1])><x-admin.icon name="folder" size="size-4" /></span>

    <a href="{{ route('admin.categories.edit', $category) }}" wire:navigate class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700">{{ $category->name }}</span>
        <span class="block truncate text-xs text-slate-500">
            @if ($path)
                {{ $path }} ·
            @endif
            <span class="font-mono">/{{ $category->slug }}</span>
            <span class="lg:hidden"> · {{ trans_choice('admin.categories.attributes_count', $category->attributes_count, ['count' => $category->attributes_count]) }}</span>
        </span>
    </a>

    <span class="hidden w-24 text-center text-sm tabular-nums text-slate-600 lg:block">{{ $category->attributes_count }}</span>
    <span class="hidden w-24 text-center text-sm tabular-nums text-slate-600 lg:block">{{ $category->productsCount() }}</span>
    <span class="shrink-0 lg:w-24">
        @if ($category->is_active)
            <x-admin.badge color="brand" pill dot>{{ __('admin.catalog.active') }}</x-admin.badge>
        @else
            <x-admin.badge color="muted" pill dot>{{ __('admin.catalog.inactive') }}</x-admin.badge>
        @endif
    </span>

    <span class="flex shrink-0 items-center justify-end lg:w-20">
        <a href="{{ route('admin.categories.edit', $category) }}" wire:navigate class="hidden size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 sm:grid" aria-label="{{ __('admin.common.edit') }}: {{ $category->name }}"><x-admin.icon name="edit" size="size-4" /></a>
        @if ($canDelete)
            <button
                type="button"
                x-on:click="$dispatch('confirm', {
                    title: @js(__('admin.categories.delete_title')),
                    body: @js(__('admin.categories.delete_body', ['name' => $category->name])),
                    label: @js(__('admin.common.delete')),
                    component: $wire.$id, method: 'delete', params: [{{ $category->id }}],
                })"
                class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                aria-label="{{ __('admin.common.delete') }}: {{ $category->name }}"
            ><x-admin.icon name="trash" size="size-4" /></button>
        @endif
    </span>
</div>
