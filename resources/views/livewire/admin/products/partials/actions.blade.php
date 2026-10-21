{{-- Row actions: edit / delete, or restore / permanent delete in the trash. --}}
<span class="flex shrink-0 items-center justify-end">
    @if ($product->trashed())
        @if (in_array($product->id, $deletable, true))
            <button type="button" wire:click="restore({{ $product->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.products.restore') }}: {{ $product->name }}" title="{{ __('admin.products.restore') }}"><x-admin.icon name="restore" size="size-4" /></button>
        @endif
        @if ($canForceDelete)
            <button
                type="button"
                x-on:click="$dispatch('confirm', {
                    title: @js(__('admin.products.force_delete_title')),
                    body: @js(__('admin.products.force_delete_body', ['name' => $product->name])),
                    label: @js(__('admin.products.force_delete')),
                    component: $wire.$id, method: 'forceDelete', params: [{{ $product->id }}],
                })"
                class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                aria-label="{{ __('admin.products.force_delete') }}: {{ $product->name }}"
                title="{{ __('admin.products.force_delete') }}"
            ><x-admin.icon name="trash" size="size-4" /></button>
        @endif
    @else
        @if (in_array($product->id, $editable, true))
            <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $product->name }}" data-edit><x-admin.icon name="edit" size="size-4" /></a>
        @else
            <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.products.view') }}: {{ $product->name }}"><x-admin.icon name="eye" size="size-4" /></a>
        @endif
        @if (in_array($product->id, $deletable, true))
            <button
                type="button"
                x-on:click="$dispatch('confirm', {
                    title: @js(__('admin.products.delete_title')),
                    body: @js(__('admin.products.delete_body', ['name' => $product->name])),
                    label: @js(__('admin.common.delete')),
                    component: $wire.$id, method: 'delete', params: [{{ $product->id }}],
                })"
                class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                aria-label="{{ __('admin.common.delete') }}: {{ $product->name }}"
                data-delete
            ><x-admin.icon name="trash" size="size-4" /></button>
        @endif
    @endif
</span>
