<span class="flex shrink-0 items-center justify-end">
    <a href="{{ route('admin.attributes.edit', $attribute) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $attribute->name }}"><x-admin.icon name="edit" size="size-4" /></a>
    @if ($canDelete)
        <button
            type="button"
            x-on:click="$dispatch('confirm', {
                title: @js(__('admin.attributes.delete_title')),
                body: @js(trans_choice('admin.attributes.delete_body', $attribute->categories_count, ['name' => $attribute->name, 'count' => $attribute->categories_count])),
                label: @js(__('admin.common.delete')),
                component: $wire.$id, method: 'delete', params: [{{ $attribute->id }}],
            })"
            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
            aria-label="{{ __('admin.common.delete') }}: {{ $attribute->name }}"
        ><x-admin.icon name="trash" size="size-4" /></button>
    @endif
</span>
