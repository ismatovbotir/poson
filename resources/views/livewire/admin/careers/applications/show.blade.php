{{--
    One job application (App\Livewire\Admin\Careers\Applications\Show). Never renders
    cv_file_id or cv_pending_path; the CV comes from the download route only.
--}}
@php
    $when = fn ($date) => $date?->timezone('Asia/Tashkent')->format('d.m.Y H:i');
    $target = $application->isOpenApplication()
        ? __('careers.admin.applications.open_application').': '.$application->departmentLabel()
        : (string) $application->vacancy?->getTranslation('title', 'uz', false);
@endphp
<div>
    <x-admin.page-header :title="'#J-'.$application->id.' · '.$application->name">
        <x-slot:breadcrumbs>
            {{ __('menu.groups.careers') }} <span class="text-slate-300">/</span>
            <a href="{{ route('admin.careers.applications.index') }}" wire:navigate class="hover:text-slate-700">{{ __('careers.admin.applications.title') }}</a>
        </x-slot:breadcrumbs>
        @if ($canDelete)
            <x-slot:actions>
                <x-admin.button variant="danger" icon="trash" x-on:click="$dispatch('confirm', {
                    title: {{ \Illuminate\Support\Js::from(__('careers.admin.applications.delete_title')) }},
                    body: {{ \Illuminate\Support\Js::from(__('careers.admin.applications.delete_body')) }},
                    label: {{ \Illuminate\Support\Js::from(__('admin.common.delete')) }},
                    component: $wire.$id, method: 'delete', params: [],
                })" data-delete-application>{{ __('admin.common.delete') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:gap-6">
        <div class="min-w-0 space-y-4 lg:space-y-6">
            <x-admin.card :title="__('careers.admin.applications.details')">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">{{ __('careers.admin.applications.vacancy') }}</dt><dd class="font-semibold">{{ $target }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('careers.admin.fields.department') }}</dt><dd class="font-semibold">{{ $application->departmentLabel() }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('careers.admin.applications.phone') }}</dt><dd><a href="tel:{{ $application->phone }}" class="font-semibold text-brand-700 hover:underline">{{ \App\Support\Phone::display($application->phone) }}</a> <x-admin.foreign-phone :phone="$application->phone" /></dd></div>
                    <div><dt class="text-slate-500">{{ __('careers.admin.applications.email') }}</dt><dd class="font-semibold">{{ $application->email ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('careers.admin.applications.locale') }}</dt><dd class="font-semibold uppercase">{{ $application->locale }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('careers.admin.applications.received') }}</dt><dd class="font-semibold">{{ $when($application->created_at) }}</dd></div>
                </dl>
                @if ($application->message)
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <p class="text-sm text-slate-500">{{ __('careers.admin.applications.message') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-800" data-application-message>{{ $application->message }}</p>
                    </div>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('careers.admin.applications.timeline')">
                @if ($this->activities->isEmpty())
                    <p class="text-sm text-slate-500">{{ __('careers.admin.applications.timeline_empty') }}</p>
                @else
                    <ol class="relative space-y-4 border-l border-slate-200 pl-5">
                        @foreach ($this->activities as $activity)
                            <li wire:key="activity-{{ $activity->id }}" class="relative" data-activity="{{ $activity->type->value }}">
                                <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                                <p class="text-sm text-slate-800">
                                    @if ($activity->type === \App\Enums\ActivityType::StatusChange)
                                        {{ \App\Enums\JobApplicationStatus::tryFrom((string) ($activity->meta['from'] ?? ''))?->label() }} → {{ \App\Enums\JobApplicationStatus::tryFrom((string) ($activity->meta['to'] ?? ''))?->label() }}
                                    @elseif ($activity->type === \App\Enums\ActivityType::Note)
                                        {{ __('careers.admin.applications.note_updated') }}
                                    @elseif ($activity->type === \App\Enums\ActivityType::Notification)
                                        {{ __('careers.admin.applications.cv_forwarded') }}
                                    @elseif ($activity->type === \App\Enums\ActivityType::Created)
                                        {{ __('careers.admin.applications.created') }}
                                    @else
                                        {{ $activity->type->label() }}
                                    @endif
                                    @if ($activity->user)<span class="text-slate-500">· {{ $activity->user->name }}</span>@endif
                                </p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ $activity->type->label() }} · {{ $when($activity->created_at) }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('careers.admin.applications.cv')">
                <p class="flex items-center gap-2 text-sm"><x-admin.badge :color="$application->cv_status->color()">{{ $application->cv_status->label() }}</x-admin.badge>
                    @if ($application->cv_size)<span class="text-slate-500">{{ number_format($application->cv_size / 1024, 0, '.', ' ') }} KB</span>@endif
                </p>
                @if ($application->cv_original_name)
                    <p class="mt-2 break-all text-sm text-slate-600">{{ $application->cv_original_name }}</p>
                @endif
                <p class="mt-2 text-xs text-slate-500">{{ __('careers.admin.applications.cv_hint.'.$application->cv_status->value) }}</p>
                @if ($application->cv_status === \App\Enums\JobApplicationCvStatus::Forwarded)
                    @can('downloadCv', $application)
                        <x-admin.button class="mt-3 w-full" variant="secondary" icon="download" :href="route('admin.careers.applications.cv', $application->id)" data-download-cv>{{ __('careers.admin.applications.download_cv') }}</x-admin.button>
                    @endcan
                @endif
            </x-admin.card>

            <x-admin.card :title="__('careers.admin.applications.processing')">
                <form wire:submit="save" class="space-y-4">
                    <x-admin.select wire:model="status" :label="__('careers.admin.applications.status')" :options="collect(\App\Enums\JobApplicationStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :disabled="! $canUpdate" />
                    <x-admin.textarea wire:model="internalNote" :label="__('careers.admin.applications.note')" :hint="__('careers.admin.applications.note_hint')" rows="5" maxlength="5000" :disabled="! $canUpdate" />
                    @if ($canUpdate)
                        <x-admin.button type="submit" class="w-full" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                    @endif
                </form>
            </x-admin.card>
        </aside>
    </div>
</div>
