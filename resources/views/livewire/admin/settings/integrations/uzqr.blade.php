@php
    /** @var array{active_mode: \App\Enums\PaymentMode, test_available: bool, go_live: bool, missing: list<string>, prod_host: string|null, test_host: string|null, auth_failed: bool, open: int} $uzqr */
    $t = 'admin.settings.integrations.uzqr';
@endphp

{{-- Dynamic UzQR (plan 11e): base URL hosts (.env only), go-live gate, auth flag, connection test. Never a key. --}}
<div class="mt-5 space-y-3 border-t border-slate-100 pt-5 text-sm" data-uzqr-panel>
    <dl class="grid gap-2 sm:grid-cols-2">
        <div><dt class="text-xs text-slate-500">{{ __($t.'.active_mode') }}</dt><dd class="font-semibold" data-uzqr-active-mode>{{ $uzqr['active_mode']->label() }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __($t.'.open_invoices') }}</dt><dd class="font-semibold tabular-nums">{{ $uzqr['open'] }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __($t.'.prod_host') }}</dt><dd class="font-mono text-xs">{{ $uzqr['prod_host'] ?? __($t.'.host_missing') }}</dd></div>
        <div><dt class="text-xs text-slate-500">{{ __($t.'.test_host') }}</dt><dd class="font-mono text-xs">{{ $uzqr['test_host'] ?? __($t.'.host_missing') }}</dd></div>
    </dl>
    <p class="text-xs text-slate-500">{{ __($t.'.hosts_hint') }}</p>

    @unless ($uzqr['test_available'])
        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900" role="note" data-uzqr-no-test-host>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.no_test_host') }}
        </p>
    @endunless

    @unless ($uzqr['go_live'])
        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900" role="note" data-uzqr-gate>
            <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.gate_closed', ['items' => implode(', ', $uzqr['missing'])]) }}
        </p>
    @endunless

    @if ($uzqr['auth_failed'])
        <p class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-red-900" role="alert" data-uzqr-auth-failed>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.auth_failed') }}
        </p>
    @endif

    <x-admin.button variant="soft" size="sm" icon="plug" wire:click="test('uzqr')" loading="test" data-test="uzqr">{{ __('admin.settings.integrations.test.button') }}</x-admin.button>

    @isset($testResults['uzqr_connection'])
        @include('livewire.admin.settings.integrations.test-result', ['result' => $testResults['uzqr_connection']])
    @endisset
</div>
