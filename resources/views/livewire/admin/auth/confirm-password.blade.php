<div x-data="{ show: false }">
    @if (\App\Actions\Auth\AuthenticateStaff::devLoginEnabled())
        <p class="mb-5 inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-amber-900" role="note" data-dev-login>
            <x-admin.icon name="alert" size="size-4" class="text-warn" />{{ __('admin.confirm_password.dev_badge') }}
        </p>
    @endif

    <span class="grid size-12 place-items-center rounded-xl bg-brand-50 text-brand-600"><x-admin.icon name="shield" size="size-6" /></span>
    <h1 class="mt-4 text-2xl font-bold tracking-tight">{{ __('admin.confirm_password.title') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">{{ __('admin.confirm_password.subtitle', ['minutes' => intdiv(\App\Support\Admin\PasswordConfirmation::timeout(), 60)]) }}</p>

    <form wire:submit="confirm" class="mt-6 space-y-4" novalidate>
        <p class="text-sm"><span class="text-slate-500">{{ __('admin.confirm_password.account') }}</span> <span class="font-semibold">{{ auth()->user()->email }}</span></p>

        <div>
            <label for="confirm-password" class="text-sm font-semibold">{{ __('admin.auth.password') }}</label>
            <div class="relative mt-1.5">
                <x-admin.icon name="lock" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    id="confirm-password"
                    x-bind:type="show ? 'text' : 'password'"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    autofocus
                    required
                    @error('password') aria-invalid="true" aria-describedby="confirm-password-error" @enderror
                    class="h-12 w-full rounded-lg border border-slate-200 pl-10 pr-11 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm"
                >
                <button
                    type="button"
                    x-on:click="show = !show"
                    x-bind:aria-pressed="show.toString()"
                    x-bind:aria-label="show ? @js(__('admin.auth.hide_password')) : @js(__('admin.auth.show_password'))"
                    aria-label="{{ __('admin.auth.show_password') }}"
                    class="absolute right-1.5 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-md text-slate-400 hover:text-slate-700"
                ><x-admin.icon name="eye" /></button>
            </div>
            <x-admin.field-error name="password" id="confirm-password-error" />
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="confirm" class="flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-brand-600 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-70">
            <svg wire:loading wire:target="confirm" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span wire:loading.remove wire:target="confirm">{{ __('admin.confirm_password.submit') }}</span>
            <span wire:loading wire:target="confirm">{{ __('admin.auth.submitting') }}</span>
        </button>

        <a href="{{ route('admin.dashboard') }}" class="flex h-11 w-full items-center justify-center rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('admin.confirm_password.cancel') }}</a>
    </form>
</div>
