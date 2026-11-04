@php
    $t = 'admin.settings.integrations';
    $icons = ['telegram' => 'telegram', 'payme' => 'card', 'click' => 'card', 'uzqr' => 'card', 'monitoring' => 'radar', 'indexnow' => 'globe'];
@endphp

{{-- Settings → Integrations (plan 04b T7). Secrets are write-only: only their status is rendered. --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')" />

    {{-- Old links to the Telegram section (the hash never reaches the server, plan 21 T21-4). --}}
    <span hidden x-data x-init="if (location.hash === '#section-telegram') location.replace(@js(route('admin.telegram.settings')))"></span>

    @include('livewire.admin.settings.integrations.password-banner')

    <nav class="scrollbar-none mt-4 flex gap-2 overflow-x-auto" aria-label="{{ __($t.'.sections_label') }}">
        @foreach (\App\Support\Integrations\IntegrationRegistry::GROUPS as $group)
            <a @if ($group === 'telegram') href="{{ route('admin.telegram.settings') }}" wire:navigate @else href="#section-{{ $group }}" @endif class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                <x-admin.icon :name="$icons[$group]" size="size-4" />{{ __($t.'.groups.'.$group) }}
            </a>
        @endforeach
    </nav>

    <fieldset class="mt-5 max-w-4xl space-y-4 pb-10 lg:space-y-6" @disabled(! $passwordConfirmed)>
        <legend class="sr-only">{{ __($t.'.title') }}</legend>

        @foreach ($sections as $group => $fields)
            @include('livewire.admin.settings.integrations.section')
        @endforeach
    </fieldset>

    {{-- Settings change history (all areas, never values) --}}
    <section class="max-w-4xl" aria-labelledby="audit-title" data-audit>
        <h2 id="audit-title" class="text-lg font-bold tracking-tight">{{ __($t.'.audit.title') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ __($t.'.audit.subtitle', ['count' => \App\Livewire\Admin\Settings\Integrations::AUDIT_LIMIT]) }}</p>

        @include('livewire.admin.settings.integrations.audit-list')
    </section>
</div>
