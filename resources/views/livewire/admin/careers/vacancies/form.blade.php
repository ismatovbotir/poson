<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.careers.vacancies.index')" :back-label="__('careers.admin.vacancies.title')">
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.careers.vacancies.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('careers.admin.sections.texts')" :description="__('careers.admin.texts_help')">
                <x-admin.locale-tabs :error-keys="['form.title', 'form.slug', 'form.summary', 'form.description', 'form.requirements', 'form.offer', 'form.meta_title', 'form.meta_description']">
                    @foreach (config('app.supported_locales') as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <div class="space-y-4">
                                <x-admin.input wire:model="form.title.{{ $locale }}" maxlength="{{ \App\Models\Vacancy::TITLE_MAX }}" :label="__('careers.admin.fields.title')" :required="$locale === 'uz'" />
                                <x-admin.input wire:model="form.slug.{{ $locale }}" maxlength="{{ \App\Models\Vacancy::SLUG_MAX }}" :label="__('careers.admin.fields.slug')" :hint="__('careers.admin.slug_hint')" />
                                <x-admin.textarea wire:model="form.summary.{{ $locale }}" rows="3" maxlength="{{ \App\Models\Vacancy::SUMMARY_MAX }}" :label="__('careers.admin.fields.summary')" :required="$locale === 'uz'" />
                                <x-admin.textarea wire:model="form.description.{{ $locale }}" rows="10" :label="__('careers.admin.fields.description')" :hint="__('careers.admin.description_hint')" :required="$locale === 'uz'" />
                                <x-admin.textarea wire:model="form.requirements.{{ $locale }}" rows="6" :label="__('careers.admin.fields.requirements')" :hint="__('careers.admin.list_hint')" />
                                <x-admin.textarea wire:model="form.offer.{{ $locale }}" rows="6" :label="__('careers.admin.fields.offer')" :hint="__('careers.admin.list_hint')" />
                                <x-admin.input wire:model="form.meta_title.{{ $locale }}" maxlength="70" :label="__('careers.admin.fields.meta_title')" />
                                <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" rows="2" maxlength="200" :label="__('careers.admin.fields.meta_description')" />
                            </div>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('careers.admin.sections.main')">
                <div class="space-y-4">
                    <x-admin.select wire:model="form.department" :label="__('careers.admin.fields.department')" :placeholder="__('careers.admin.choose')" :options="collect(\App\Enums\VacancyDepartment::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" required />
                    <x-admin.select wire:model="form.location" :label="__('careers.admin.fields.location')" :placeholder="__('careers.admin.choose')" :options="collect(\App\Enums\Region::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" required />
                    <x-admin.select wire:model="form.employment_type" :label="__('careers.admin.fields.employment_type')" :placeholder="__('careers.admin.choose')" :options="collect(\App\Enums\EmploymentType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" required />
                    <x-admin.input wire:model="form.valid_through" type="date" :label="__('careers.admin.fields.valid_through')" :hint="__('careers.admin.valid_through_hint')" />
                    <x-admin.input wire:model="form.sort" type="number" min="0" max="65535" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                </div>
            </x-admin.card>

            <x-admin.card :title="__('careers.admin.sections.publish')">
                <x-admin.toggle wire:model="form.is_published" :label="__('careers.admin.fields.is_published')" :description="__('careers.admin.published_hint')" />
                <x-admin.field-error name="form.is_published" class="mt-2" />
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    @if ($applicationsCount > 0)
                        <p class="mt-1 text-xs text-slate-500" data-vacancy-has-applications>{{ __('careers.admin.vacancies.delete_blocked', ['count' => $applicationsCount]) }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">{{ __('careers.admin.vacancies.delete_help') }}</p>
                        <button
                            type="button"
                            x-on:click="$dispatch('confirm', {
                                title: @js(__('careers.admin.vacancies.delete_title')),
                                body: @js(__('careers.admin.vacancies.delete_body', ['name' => $title])),
                                label: @js(__('admin.common.delete')),
                                component: $wire.$id, method: 'delete',
                            })"
                            class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                        >{{ __('admin.common.delete') }}</button>
                    @endif
                </section>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route('admin.careers.vacancies.index')" />
    @endif
</form>
