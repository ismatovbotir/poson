@php($vacancyTitle = (string) $vacancy->getTranslation('title', 'uz', false))
<span class="flex shrink-0 items-center justify-end">
    @if ($canUpdate)
        <button type="button" wire:click="togglePublished({{ $vacancy->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ ($vacancy->is_published ? __('careers.admin.vacancies.unpublish') : __('careers.admin.vacancies.publish')).': '.$vacancyTitle }}"><x-admin.icon :name="$vacancy->is_published ? 'x' : 'eye'" size="size-4" /></button>
    @endif
    <a href="{{ route('admin.careers.vacancies.edit', $vacancy) }}" wire:navigate class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $vacancyTitle }}"><x-admin.icon name="edit" size="size-4" /></a>
    @if ($canDelete && $vacancy->applications_count === 0)
        <button
            type="button"
            x-on:click="$dispatch('confirm', {
                title: @js(__('careers.admin.vacancies.delete_title')),
                body: @js(__('careers.admin.vacancies.delete_body', ['name' => $vacancyTitle])),
                label: @js(__('admin.common.delete')),
                component: $wire.$id, method: 'delete', params: [{{ $vacancy->id }}],
            })"
            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
            aria-label="{{ __('admin.common.delete') }}: {{ $vacancyTitle }}"
        ><x-admin.icon name="trash" size="size-4" /></button>
    @endif
</span>
