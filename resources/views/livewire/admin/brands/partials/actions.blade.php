<span class="flex shrink-0 items-center justify-end">
    <a href="{{ route('admin.brands.edit', $brand) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $brand->name }}"><x-admin.icon name="edit" size="size-4" /></a>
    @if ($canDelete)
        <button
            type="button"
            x-on:click="$dispatch('confirm', {
                title: @js(__('admin.brands.delete_title')),
                body: @js(__('admin.brands.delete_body', ['name' => $brand->name])),
                label: @js(__('admin.common.delete')),
                component: $wire.$id, method: 'delete', params: [{{ $brand->id }}],
            })"
            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
            aria-label="{{ __('admin.common.delete') }}: {{ $brand->name }}"
        ><x-admin.icon name="trash" size="size-4" /></button>
    @endif
</span>
