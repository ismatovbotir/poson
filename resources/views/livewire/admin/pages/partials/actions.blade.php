<span class="flex shrink-0 items-center justify-end">
    <a href="{{ route('admin.pages.edit', $page) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $page->title }}"><x-admin.icon name="edit" size="size-4" /></a>
    @if ($canDelete && ! $page->is_system)
        <button
            type="button"
            x-on:click="$dispatch('confirm', {
                title: @js(__('admin.pages.delete_title')),
                body: @js(__('admin.pages.delete_body', ['name' => (string) $page->title])),
                label: @js(__('admin.common.delete')),
                component: $wire.$id, method: 'delete', params: [{{ $page->id }}],
            })"
            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
            aria-label="{{ __('admin.common.delete') }}: {{ $page->title }}"
            data-delete-page="{{ $page->slug }}"
        ><x-admin.icon name="trash" size="size-4" /></button>
    @endif
</span>
