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
        <p class="mt-2 flex items-start gap-1.5 text-xs text-slate-500" data-label-prices-hint><x-admin.icon name="info" size="size-4" class="shrink-0 text-slate-400" />{{ __($t.'.label_prices_hint') }}</p>
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
                        @php $state = $token->state(); @endphp
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

    {{-- How to use the API: static, read-only (contract: .claude/docs/api/prices.md). Constant placeholders only, nothing from the tokens above. --}}
    @php
        $d = 'admin.api_tokens.docs';
        $limits = \App\Support\Api\PriceApiDocs::limits();
        $maxSom = number_format(\App\Actions\Api\SyncPrices::MAX_SOM, 0, '.', ' ');
        $blocks = [
            'request' => \App\Support\Api\PriceApiDocs::requestJson(),
            'response' => \App\Support\Api\PriceApiDocs::responseJson(),
            'curl' => \App\Support\Api\PriceApiDocs::curl(),
        ];
        $fieldRows = [
            ['items', $d.'.t_array', $d.'.yes', __($d.'.r_items', ['max' => $limits['items']])],
            ['code', $d.'.t_code', $d.'.yes', __($d.'.r_code', ['max' => \App\Actions\Api\SyncPrices::CODE_MAX])],
            ['retail', $d.'.t_int', $d.'.yes', __($d.'.r_retail', ['max' => $maxSom])],
            ['dealer', $d.'.t_int_null', $d.'.yes_key', __($d.'.r_level', ['max' => $maxSom])],
            ['wholesale', $d.'.t_int_null', $d.'.yes_key', __($d.'.r_level', ['max' => $maxSom])],
        ];
        $statusRows = ['updated', 'unchanged', 'unknown', 'invalid'];
        $errorRows = [
            ['item_not_object', __($d.'.e_item_not_object')],
            ['code_required, code_not_string, code_too_long', __($d.'.e_code', ['max' => \App\Actions\Api\SyncPrices::CODE_MAX])],
            ['code_invalid', __($d.'.e_code_invalid')],
            ['retail_required, dealer_required, wholesale_required', __($d.'.e_required')],
            ['retail_not_integer, dealer_not_integer, wholesale_not_integer', __($d.'.e_type')],
            ['retail_out_of_range, dealer_out_of_range, wholesale_out_of_range', __($d.'.e_range', ['max' => $maxSom])],
            ['duplicate_code', __($d.'.e_duplicate')],
        ];
        $httpRows = [
            '200' => __($d.'.s200'),
            '401' => __($d.'.s401'),
            '413' => __($d.'.s413', ['kb' => $limits['body_kb']]),
            '415' => __($d.'.s415'),
            '422' => __($d.'.s422'),
            '429' => __($d.'.s429'),
            '500' => __($d.'.s500'),
            '404 / 405' => __($d.'.s404'),
        ];
    @endphp
    <section x-data="{ open: false }" class="mt-5 min-w-0 rounded-2xl border border-slate-200 bg-white" data-api-docs>
        <h2 class="text-base font-semibold">
            <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left sm:px-6" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="api-docs-body" data-api-docs-toggle>
                <span class="min-w-0">{{ __($d.'.title') }}<span class="mt-0.5 block text-sm font-normal text-slate-500">{{ __($d.'.subtitle') }}</span></span>
                <span class="shrink-0 text-slate-400 transition-transform" x-bind:class="{ 'rotate-180': open }"><x-admin.icon name="chevron-down" size="size-5" /></span>
            </button>
        </h2>

        <div id="api-docs-body" x-cloak x-show="open" class="min-w-0 space-y-6 border-t border-slate-100 p-4 text-sm sm:p-6">
            {{-- Endpoint + headers --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.endpoint') }}</h3>
                <p class="mt-2 flex flex-wrap items-center gap-2">
                    <x-admin.badge color="ink">POST</x-admin.badge>
                    <code class="break-all rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700">{{ $endpoint }}</code>
                </p>
                <p class="mt-2 text-xs text-slate-500">{{ __($d.'.https_only') }}</p>
            </div>

            <div>
                <h3 class="font-semibold">{{ __($d.'.headers') }}</h3>
                <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[34rem] text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 font-semibold">{{ __($d.'.h_col') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.h_value') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_meaning') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-3 py-2 font-mono">Authorization</td><td class="px-3 py-2 font-mono break-all">Bearer {{ \App\Support\Api\PriceApiDocs::PLACEHOLDER_TOKEN }}</td><td class="px-3 py-2">{{ __($d.'.h_auth') }}</td></tr>
                            <tr><td class="px-3 py-2 font-mono">Content-Type</td><td class="px-3 py-2 font-mono">application/json</td><td class="px-3 py-2">{{ __($d.'.h_ct') }}</td></tr>
                            <tr><td class="px-3 py-2 font-mono">Accept</td><td class="px-3 py-2 font-mono">application/json</td><td class="px-3 py-2">{{ __($d.'.h_accept') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ __($d.'.token_note') }}</p>
            </div>

            {{-- Request --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.request') }}</h3>
                @include('livewire.admin.settings.partials.api-docs-code', ['key' => 'request', 'code' => $blocks['request'], 'label' => __($d.'.request')])
                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($d.'.fields') }}</h4>
                <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[40rem] text-left text-xs" data-api-docs-fields>
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 font-semibold">{{ __($d.'.c_field') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_type') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_required') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_rules') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($fieldRows as [$field, $type, $required, $rules])
                                <tr><td class="px-3 py-2 font-mono">{{ $field }}</td><td class="px-3 py-2">{{ __($type) }}</td><td class="px-3 py-2">{{ __($required) }}</td><td class="px-3 py-2">{{ $rules }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ __($d.'.r_replace') }}</p>
            </div>

            {{-- Response --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.response') }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __($d.'.response_help') }}</p>
                @include('livewire.admin.settings.partials.api-docs-code', ['key' => 'response', 'code' => $blocks['response'], 'label' => __($d.'.response')])
                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($d.'.statuses') }}</h4>
                <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[26rem] text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 font-semibold">{{ __($d.'.c_status') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_meaning') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($statusRows as $status)
                                <tr><td class="px-3 py-2 font-mono">{{ $status }}</td><td class="px-3 py-2">{{ __($d.'.s_'.$status) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($d.'.errors') }}</h4>
                <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[34rem] text-left text-xs">
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 font-semibold">{{ __($d.'.c_code') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_meaning') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($errorRows as [$code, $meaning])
                                <tr><td class="px-3 py-2 font-mono">{{ $code }}</td><td class="px-3 py-2">{{ $meaning }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- HTTP statuses --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.http') }}</h3>
                <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full min-w-[26rem] text-left text-xs" data-api-docs-http>
                        <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 font-semibold">{{ __($d.'.c_http') }}</th><th class="px-3 py-2 font-semibold">{{ __($d.'.c_meaning') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($httpRows as $status => $meaning)
                                <tr><td class="px-3 py-2 font-mono">{{ $status }}</td><td class="px-3 py-2">{{ $meaning }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Limits --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.limits') }}</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-700" data-api-docs-limits>
                    <li>{{ __($d.'.l_items', ['count' => $limits['items']]) }}</li>
                    <li>{{ __($d.'.l_body', ['kb' => $limits['body_kb']]) }}</li>
                    <li>{{ __($d.'.l_rate', ['count' => $limits['per_minute']]) }}</li>
                    <li>{{ __($d.'.l_failures', ['count' => $limits['failures']]) }}</li>
                    <li>{{ __($d.'.l_retry') }}</li>
                </ul>
            </div>

            {{-- curl --}}
            <div>
                <h3 class="font-semibold">{{ __($d.'.curl') }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ __($d.'.curl_hint') }}</p>
                @include('livewire.admin.settings.partials.api-docs-code', ['key' => 'curl', 'code' => $blocks['curl'], 'label' => __($d.'.curl')])
            </div>
        </div>
    </section>
</div>
