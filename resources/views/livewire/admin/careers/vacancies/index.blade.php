<div>
    <x-admin.page-header :title="__('careers.admin.vacancies.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.careers') }} <span class="text-slate-300">/</span> {{ __('careers.admin.vacancies.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.careers.vacancies.create')" wire:navigate icon="plus">{{ __('careers.admin.vacancies.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <p class="mt-2 max-w-3xl text-sm text-slate-500">{{ __('careers.admin.vacancies.intro') }}</p>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                <option value="published">{{ __('careers.admin.state.published') }}</option>
                <option value="draft">{{ __('careers.admin.state.draft') }}</option>
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('careers.admin.fields.department') }}</span>
            <select wire:model.live="department" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __('careers.admin.vacancies.all_departments') }}</option>
                @foreach (\App\Enums\VacancyDepartment::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
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
                <x-admin.empty-state icon="briefcase" :title="__('careers.admin.vacancies.empty_title')" :body="__('careers.admin.vacancies.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.careers.vacancies.create')" wire:navigate icon="plus">{{ __('careers.admin.vacancies.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('careers.admin.vacancies.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('careers.admin.fields.title') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.fields.department') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.fields.location') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.fields.employment_type') }}</th>
                    <th class="px-4 py-3 text-center">{{ __('careers.admin.vacancies.applications') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    <th class="w-32 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $vacancy)
                    <tr wire:key="vacancy-row-{{ $vacancy->id }}" class="hover:bg-slate-50/70" data-vacancy="{{ $vacancy->id }}">
                        <td class="py-3 pl-5 pr-4"><a href="{{ route('admin.careers.vacancies.edit', $vacancy) }}" wire:navigate class="font-semibold text-slate-900 hover:text-brand-700">{{ $vacancy->getTranslation('title', 'uz', false) }}</a></td>
                        <td class="px-4 py-3 text-slate-600">{{ $vacancy->department->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $vacancy->location->label() }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $vacancy->employment_type->label() }}</td>
                        <td class="px-4 py-3 text-center tabular-nums text-slate-600">{{ $vacancy->applications_count }}</td>
                        <td class="px-4 py-3">@include('livewire.admin.careers.vacancies.partials.status', ['vacancy' => $vacancy])</td>
                        <td class="py-3 pr-5">@include('livewire.admin.careers.vacancies.partials.actions', ['vacancy' => $vacancy])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $vacancy)
                    <article wire:key="vacancy-card-{{ $vacancy->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.careers.vacancies.edit', $vacancy) }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $vacancy->getTranslation('title', 'uz', false) }}</span>
                            <span class="mt-1 flex items-center gap-2 text-xs text-slate-500">@include('livewire.admin.careers.vacancies.partials.status', ['vacancy' => $vacancy]) {{ $vacancy->department->label() }}</span>
                        </a>
                        @include('livewire.admin.careers.vacancies.partials.actions', ['vacancy' => $vacancy])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
