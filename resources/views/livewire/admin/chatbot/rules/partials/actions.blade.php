@php($t = 'chatbot_admin.rules')
<span class="flex shrink-0 items-center justify-end">
    <a href="{{ route('admin.chatbot.rules.edit', $rule) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $rule->name }}"><x-admin.icon name="edit" size="size-4" /></a>
    @if (! $rule->is_system && auth()->user()->can('delete', $rule))
        <button
            type="button"
            x-on:click="$dispatch('confirm', {
                title: @js(__($t.'.delete_title')),
                body: @js(__($t.'.delete_body', ['name' => $rule->name])),
                label: @js(__('admin.common.delete')),
                component: $wire.$id, method: 'delete', params: [{{ $rule->id }}],
            })"
            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
            aria-label="{{ __('admin.common.delete') }}: {{ $rule->name }}"
            data-delete-rule="{{ $rule->id }}"
        ><x-admin.icon name="trash" size="size-4" /></button>
    @endif
</span>
