@php($t = 'admin.telegram_settings')

{{-- Telegram → Settings (plan 21 stream C). Secrets are write-only: only their status is rendered. --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')" />

    @include('livewire.admin.settings.integrations.password-banner')

    <fieldset class="mt-5 max-w-4xl space-y-4 pb-10 lg:space-y-6" @disabled(! $passwordConfirmed)>
        <legend class="sr-only">{{ __($t.'.title') }}</legend>

        @foreach ($sections as $group => $fields)
            @include('livewire.admin.settings.integrations.section')
        @endforeach
    </fieldset>

    {{-- Telegram settings change history (never values) --}}
    <section class="max-w-4xl" aria-labelledby="audit-title" data-audit>
        <h2 id="audit-title" class="text-lg font-bold tracking-tight">{{ __('admin.settings.integrations.audit.title') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ __($t.'.audit_subtitle', ['count' => \App\Livewire\Admin\Telegram\Settings::AUDIT_LIMIT]) }}</p>

        @include('livewire.admin.settings.integrations.audit-list')
    </section>
</div>
