@php($t = 'admin.settings.integrations')

{{-- Password re-confirmation (15 min) --}}
@if ($passwordConfirmed)
    <p class="mt-4 flex items-center gap-2 text-sm text-slate-500" data-password-confirmed>
        <x-admin.icon name="shield" size="size-4" class="text-brand-600" />{{ __($t.'.confirmed', ['minutes' => max(1, (int) ceil(\App\Support\Admin\PasswordConfirmation::secondsLeft() / 60))]) }}
    </p>
@else
    <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-password-required>
        <p class="flex gap-2"><x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm_required', ['minutes' => intdiv(\App\Support\Admin\PasswordConfirmation::timeout(), 60)]) }}</p>
        <x-admin.button size="sm" icon="shield" wire:click="confirmPassword">{{ __($t.'.confirm_button') }}</x-admin.button>
    </div>
@endif
