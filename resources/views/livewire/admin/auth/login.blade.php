<div x-data="{ show: false }">
    @if (\App\Actions\Auth\AuthenticateStaff::devLoginEnabled())
        <p class="mb-5 inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-amber-900" role="note" data-dev-login>
            <x-admin.icon name="alert" size="size-4" class="text-warn" />{{ __('admin.auth.dev_login_badge') }}
        </p>
    @endif

    <h1 class="text-2xl font-bold tracking-tight">{{ __('admin.auth.title') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">{{ __('admin.auth.subtitle') }}</p>

    @if (session(\App\Http\Middleware\EnsureStaff::NOTICE_KEY))
        <div class="mt-6 flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">
            <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0 text-warn" />{{ session(\App\Http\Middleware\EnsureStaff::NOTICE_KEY) }}
        </div>
    @endif

    @error('email')
        <div class="mt-6 flex gap-2.5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert" id="login-error">
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0 text-danger" />{{ $message }}
        </div>
    @enderror

    <form wire:submit="login" class="mt-6 space-y-4" novalidate>
        <div>
            <label for="login-email" class="text-sm font-semibold">{{ __('admin.auth.email') }}</label>
            <div class="relative mt-1.5">
                <x-admin.icon name="mail" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    id="login-email"
                    type="email"
                    wire:model="email"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder="{{ __('admin.auth.email_placeholder') }}"
                    @error('email') aria-invalid="true" aria-describedby="login-error" @enderror
                    class="h-12 w-full rounded-lg border border-slate-200 pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm"
                >
            </div>
        </div>

        <div>
            <label for="login-password" class="text-sm font-semibold">{{ __('admin.auth.password') }}</label>
            <div class="relative mt-1.5">
                <x-admin.icon name="lock" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    id="login-password"
                    x-bind:type="show ? 'text' : 'password'"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    required
                    @error('password') aria-invalid="true" aria-describedby="login-password-error" @enderror
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
            <x-admin.field-error name="password" id="login-password-error" />
        </div>

        <label class="flex items-center gap-2.5 text-sm text-slate-600">
            <input type="checkbox" wire:model="remember" class="size-4 rounded accent-brand-600">
            {{ __('admin.auth.remember') }}
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login" class="flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-brand-600 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-70">
            <svg wire:loading wire:target="login" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span wire:loading.remove wire:target="login">{{ __('admin.auth.submit') }}</span>
            <span wire:loading wire:target="login">{{ __('admin.auth.submitting') }}</span>
        </button>
    </form>

    <p class="mt-8 flex items-center gap-2 text-xs text-slate-400"><x-admin.icon name="shield" size="size-4" />{{ __('admin.auth.note') }}</p>
</div>
