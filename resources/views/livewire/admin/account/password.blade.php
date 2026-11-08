<div>
    <span class="grid size-12 place-items-center rounded-xl bg-brand-50 text-brand-600"><x-admin.icon name="shield" size="size-6" /></span>
    <h1 class="mt-4 text-2xl font-bold tracking-tight">{{ __('staff.account.title') }}</h1>

    @if ($user?->mustChangePassword())
        <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert" data-forced-change>
            {{ __('staff.account.forced_notice', ['date' => $user->temp_password_expires_at->format('d.m.Y H:i')]) }}
        </p>
    @else
        <p class="mt-1.5 text-sm text-slate-500">{{ __('staff.account.subtitle') }}</p>
    @endif

    <p class="mt-3 text-sm text-slate-500">{{ __('staff.account.sessions_notice') }}</p>

    @if ($hasMcpAccess)
        <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="note" data-mcp-warning>
            {{ __('staff.account.mcp_warning') }}
        </p>
    @endif

    <form wire:submit="save" class="mt-6 space-y-4" novalidate>
        @foreach ([
            ['name' => 'currentPassword', 'label' => __('staff.account.current_password'), 'autocomplete' => 'current-password', 'hint' => null],
            ['name' => 'newPassword', 'label' => __('staff.account.new_password'), 'autocomplete' => 'new-password', 'hint' => __('staff.account.new_password_hint', ['min' => \App\Rules\StaffPassword::MIN_CHARACTERS])],
            ['name' => 'newPasswordConfirmation', 'label' => __('staff.account.confirm_new_password'), 'autocomplete' => 'new-password', 'hint' => null],
        ] as $field)
            <div wire:key="{{ $field['name'] }}" x-data="{ show: false }">
                <label for="{{ $field['name'] }}" class="text-sm font-semibold">{{ $field['label'] }}</label>
                <div class="relative mt-1.5">
                    <x-admin.icon name="lock" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        id="{{ $field['name'] }}"
                        x-bind:type="show ? 'text' : 'password'"
                        type="password"
                        wire:model="{{ $field['name'] }}"
                        autocomplete="{{ $field['autocomplete'] }}"
                        required
                        @error($field['name']) aria-invalid="true" aria-describedby="{{ $field['name'] }}-error" @enderror
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
                @if ($field['hint'])
                    <p class="mt-1.5 text-xs text-slate-500">{{ $field['hint'] }}</p>
                @endif
                <x-admin.field-error :name="$field['name']" :id="$field['name'].'-error'" />
            </div>
        @endforeach

        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-brand-600 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-70">
            <svg wire:loading wire:target="save" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span wire:loading.remove wire:target="save">{{ __('staff.account.submit') }}</span>
            <span wire:loading wire:target="save">{{ __('admin.auth.submitting') }}</span>
        </button>

        @unless ($user?->mustChangePassword())
            <a href="{{ route('admin.dashboard') }}" class="flex h-11 w-full items-center justify-center rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('staff.account.cancel') }}</a>
        @endunless
    </form>

    <form method="POST" action="{{ route('admin.logout') }}" class="mt-2">
        @csrf
        <button type="submit" class="flex h-11 w-full items-center justify-center gap-2 rounded-lg text-sm font-semibold text-danger hover:bg-red-50"><x-admin.icon name="logout" size="size-4" />{{ __('admin.topbar.logout') }}</button>
    </form>
</div>
