@php($t = 'careers.admin.settings')

{{-- Careers → Application form (plan 31 CR-3 / CR-11). Admin only; every change is audited without values. --}}
<form wire:submit="save" novalidate class="pb-28" data-careers-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.careers') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 lg:space-y-6">
        <x-admin.card :title="__($t.'.switches')" :description="__($t.'.switches_help')">
            <div class="space-y-4">
                <x-admin.toggle wire:model="applications_enabled" :label="__($t.'.applications_enabled')" :description="__($t.'.applications_enabled_hint')" />
                <x-admin.toggle wire:model="open_applications_enabled" :label="__($t.'.open_applications_enabled')" :description="__($t.'.open_applications_enabled_hint')" />
                <x-admin.toggle wire:model="cv_enabled" :label="__($t.'.cv_enabled')" :description="__($t.'.cv_enabled_hint')" />
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.status')" :description="__($t.'.status_help')">
            <dl class="grid gap-4 text-sm sm:grid-cols-2" data-careers-status>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.chat') }}</dt>
                    <dd class="mt-0.5 font-medium text-slate-900" data-chat-configured="{{ $chatConfigured ? 'yes' : 'no' }}">{{ $chatConfigured ? __($t.'.yes') : __($t.'.no') }}</dd>
                </div>
                @foreach (['applications_per_hour', 'cv_forwards_per_hour', 'pending_files', 'pending_megabytes'] as $cap)
                    <div wire:key="cap-{{ $cap }}">
                        <dt class="text-slate-500">{{ __($t.'.caps.'.$cap) }}</dt>
                        <dd class="mt-0.5 font-medium text-slate-900" data-cap="{{ $cap }}">{{ $caps[$cap] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-500">{{ __($t.'.caps_note') }}</p>
        </x-admin.card>
    </div>

    <x-admin.sticky-save-bar />
</form>
