@php
    $t = 'api.tokens';
    $tz = \App\Models\PriceSyncLog::TIMEZONE;
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $stateColors = ['active' => 'brand', 'expired' => 'amber', 'revoked' => 'muted'];
@endphp

{{-- Settings → API tokens (plan 09). A new token's value is rendered once, in the response that created it. --}}
<div x-data="{ creating: false }" x-on:api-token-created.window="creating = false">
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.settings') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button icon="plus" x-on:click="creating = true" :disabled="! $passwordConfirmed" data-new-token>{{ __($t.'.create') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Password re-confirmation (15 min) --}}
    @if ($passwordConfirmed)
        <p class="mt-4 flex items-center gap-2 text-sm text-slate-500" data-password-confirmed>
            <x-admin.icon name="shield" size="size-4" class="text-brand-600" />{{ __('admin.settings.integrations.confirmed', ['minutes' => max(1, (int) ceil(\App\Support\Admin\PasswordConfirmation::secondsLeft() / 60))]) }}
        </p>
    @else
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-password-required>
            <p class="flex gap-2"><x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm_required', ['minutes' => intdiv(\App\Support\Admin\PasswordConfirmation::timeout(), 60)]) }}</p>
            <x-admin.button size="sm" icon="shield" wire:click="confirmPassword">{{ __('admin.settings.integrations.confirm_button') }}</x-admin.button>
        </div>
    @endif

    {{-- Endpoint --}}
    <x-admin.card :title="__($t.'.endpoint')" :description="__($t.'.endpoint_help')" class="mt-5">
        <p class="flex flex-wrap items-center gap-2 text-sm">
            <x-admin.badge color="ink">POST</x-admin.badge>
            <code class="break-all rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700" data-endpoint>{{ $endpoint }}</code>
        </p>
        <p class="mt-2 text-xs text-slate-500">{{ __($t.'.endpoint_auth') }}</p>
    </x-admin.card>

    {{-- Show once --}}
    @if ($revealedToken)
        <div x-data="{ open: true, copied: false }" x-show="open" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="api-token-created-title" data-token-revealed>
            <div class="absolute inset-0 bg-slate-900/50"></div>
            <div x-trap.noscroll="open" class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-lg sm:rounded-2xl sm:pb-5">
                <h2 id="api-token-created-title" class="text-base font-semibold">{{ __($t.'.created_title', ['name' => $revealedName]) }}</h2>
                <p class="mt-1 flex gap-2 text-sm text-amber-800"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.created_body') }}</p>
                <div class="mt-4 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                    <code class="min-w-0 flex-1 break-all px-1 font-mono text-xs text-slate-800" data-plain-token>{{ $revealedToken }}</code>
                    <button type="button" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-white px-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50" x-on:click="navigator.clipboard?.writeText(@js($revealedToken)); copied = true; setTimeout(() => copied = false, 1500)">
                        <x-admin.icon name="copy" size="size-4" />
                        <span x-show="! copied">{{ __($t.'.copy') }}</span>
                        <span x-cloak x-show="copied">{{ __($t.'.copied') }}</span>
                    </button>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-admin.button x-on:click="open = false">{{ __($t.'.done') }}</x-admin.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create --}}
    <div x-cloak x-show="creating" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="api-token-create-title" x-on:keydown.escape.window="creating = false">
        <div x-show="creating" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="creating = false"></div>
        <form wire:submit="create" x-show="creating" x-transition x-trap.noscroll="creating" novalidate class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5" data-create-token>
            <h2 id="api-token-create-title" class="text-base font-semibold">{{ __($t.'.create_title') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __($t.'.create_body') }}</p>
            <div class="mt-4 space-y-4">
                <x-admin.input wire:model="name" :label="__($t.'.fields.name')" :hint="__($t.'.name_hint')" required maxlength="100" />
                <x-admin.select wire:model="expiresInDays" name="expires_in_days" :label="__($t.'.fields.expires')" :options="$expiryOptions" />
            </div>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="creating = false" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                <x-admin.button type="submit" loading="create">{{ __($t.'.create') }}</x-admin.button>
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="mt-5">
        @if ($tokens->isEmpty())
            <x-admin.empty-state icon="shield" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" />
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <ul class="divide-y divide-slate-100" data-token-list>
                    @foreach ($tokens as $token)
                        @php($state = $token->state())
                        <li wire:key="api-token-{{ $token->id }}" class="flex flex-col gap-3 px-4 py-3.5 sm:px-5 lg:flex-row lg:items-center" data-token="{{ $token->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $token->name }}</span>
                                    <x-admin.badge :color="$stateColors[$state]">{{ __($t.'.status.'.$state) }}</x-admin.badge>
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $token->maskedToken() }}</p>
                            </div>
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-600 sm:grid-cols-4 lg:w-[38rem]">
                                <div><dt class="font-semibold text-slate-500">{{ __($t.'.fields.created') }}</dt><dd>{{ $when($token->created_at, 'D MMM YYYY') }}@if ($token->creator) · {{ $token->creator->name }}@endif</dd></div>
                                <div><dt class="font-semibold text-slate-500">{{ __($t.'.fields.expires') }}</dt><dd>{{ $token->expires_at ? $when($token->expires_at, 'D MMM YYYY') : __($t.'.expiry.never') }}</dd></div>
                                <div><dt class="font-semibold text-slate-500">{{ __($t.'.fields.last_used') }}</dt><dd>{{ $token->last_used_at ? $when($token->last_used_at) : __($t.'.never_used') }}</dd></div>
                                <div><dt class="font-semibold text-slate-500">{{ __($t.'.fields.last_ip') }}</dt><dd class="font-mono">{{ $token->last_used_ip ?? __('admin.common.empty_value') }}</dd></div>
                            </dl>
                            <div class="shrink-0 lg:w-28 lg:text-right">
                                @if (! $token->isRevoked())
                                    <x-admin.button
                                        variant="secondary"
                                        size="sm"
                                        icon="x"
                                        :disabled="! $passwordConfirmed"
                                        x-on:click="$dispatch('confirm', {
                                            title: {{ \Illuminate\Support\Js::from(__($t.'.revoke_title')) }},
                                            body: {{ \Illuminate\Support\Js::from(__($t.'.revoke_body', ['name' => $token->name])) }},
                                            label: {{ \Illuminate\Support\Js::from(__($t.'.revoke')) }},
                                            component: $wire.$id, method: 'revoke', params: [{{ $token->id }}],
                                        })"
                                        data-revoke="{{ $token->id }}"
                                    >{{ __($t.'.revoke') }}</x-admin.button>
                                @else
                                    <span class="text-xs text-slate-500">{{ __($t.'.revoked_at', ['date' => $when($token->revoked_at, 'D MMM YYYY')]) }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
