@php
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
    $target = fn ($application) => $application->isOpenApplication()
        ? __('careers.admin.applications.open_application').': '.$application->departmentLabel()
        : $application->vacancy?->getTranslation('title', 'uz', false);
@endphp
<div>
    <x-admin.page-header :title="__('careers.admin.applications.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.careers') }} <span class="text-slate-300">/</span> {{ __('careers.admin.applications.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <details class="mt-3 max-w-3xl rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-careers-help>
        <summary class="cursor-pointer font-semibold text-slate-800">{{ __('careers.admin.help.title') }}</summary>
        <ol class="mt-2 list-decimal space-y-1 pl-5">
            @foreach (__('careers.admin.help.steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    </details>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" maxlength="100" placeholder="{{ __('careers.admin.applications.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('careers.admin.applications.vacancy') }}</span>
            <select wire:model.live="vacancy" class="{{ $selectClass }}">
                <option value="">{{ __('careers.admin.applications.all_vacancies') }}</option>
                <option value="open">{{ __('careers.admin.applications.open_application') }}</option>
                @foreach ($vacancies as $option)
                    <option value="{{ $option->id }}">{{ $option->getTranslation('title', 'uz', false) }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('careers.admin.fields.department') }}</span>
            <select wire:model.live="department" class="{{ $selectClass }}">
                <option value="">{{ __('careers.admin.vacancies.all_departments') }}</option>
                @foreach (\App\Enums\VacancyDepartment::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('careers.admin.applications.status') }}</span>
            <select wire:model.live="status" class="{{ $selectClass }}">
                <option value="">{{ __('careers.admin.applications.all_statuses') }}</option>
                @foreach (\App\Enums\JobApplicationStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('careers.admin.applications.cv') }}</span>
            <select wire:model.live="cvStatus" class="{{ $selectClass }}">
                <option value="">{{ __('careers.admin.applications.all_cv_statuses') }}</option>
                @foreach (\App\Enums\JobApplicationCvStatus::cases() as $case)
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
                <x-admin.empty-state icon="inbox" :title="__('careers.admin.applications.empty_title')" :body="__('careers.admin.applications.empty_body')" />
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('careers.admin.applications.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('careers.admin.applications.candidate') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.applications.vacancy') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.applications.status') }}</th>
                    <th class="px-4 py-3">{{ __('careers.admin.applications.cv') }}</th>
                    <th class="px-4 py-3 pr-5">{{ __('careers.admin.applications.received') }}</th>
                </x-slot:head>
                @foreach ($items as $application)
                    <tr wire:key="application-row-{{ $application->id }}" class="hover:bg-slate-50/70" data-application="{{ $application->id }}">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route('admin.careers.applications.show', $application->id) }}" wire:navigate class="block font-semibold text-slate-900 hover:text-brand-700"><span class="font-mono text-xs text-slate-400">#J-{{ $application->id }}</span> {{ $application->name }}</a>
                            <span class="block text-xs text-slate-500">{{ \App\Support\Phone::display($application->phone) }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $target($application) }}</td>
                        <td class="px-4 py-3"><x-admin.badge :color="$application->status->color()">{{ $application->status->label() }}</x-admin.badge></td>
                        <td class="px-4 py-3"><x-admin.badge :color="$application->cv_status->color()">{{ $application->cv_status->label() }}</x-admin.badge></td>
                        <td class="px-4 py-3 pr-5 text-slate-600">{{ $application->created_at?->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $application)
                    <a href="{{ route('admin.careers.applications.show', $application->id) }}" wire:navigate wire:key="application-card-{{ $application->id }}" class="block rounded-2xl border border-slate-200 bg-white p-3.5">
                        <span class="block text-sm font-semibold">#J-{{ $application->id }} · {{ $application->name }}</span>
                        <span class="mt-1 block text-xs text-slate-500">{{ \App\Support\Phone::display($application->phone) }} · {{ $target($application) }}</span>
                        <span class="mt-2 flex items-center gap-2"><x-admin.badge :color="$application->status->color()">{{ $application->status->label() }}</x-admin.badge><x-admin.badge :color="$application->cv_status->color()">{{ $application->cv_status->label() }}</x-admin.badge></span>
                    </a>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
