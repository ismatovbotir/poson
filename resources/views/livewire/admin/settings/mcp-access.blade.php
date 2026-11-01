@php
    $t = 'mcp';
    $tz = \App\Mcp\Tools\Account\GetMyAccess::TIMEZONE;
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $confirm = fn (string $title, string $body, string $label, string $method, array $params = []) => '$dispatch(\'confirm\', {'
        .'title: '.\Illuminate\Support\Js::from($title).', '
        .'body: '.\Illuminate\Support\Js::from($body).', '
        .'label: '.\Illuminate\Support\Js::from($label).', '
        .'component: $wire.$id, method: '.\Illuminate\Support\Js::from($method).', params: '.\Illuminate\Support\Js::from($params).'})';
@endphp

{{-- Settings → MCP access (plan 13). A new token's value is rendered once, in the response that generated it. --}}
<div x-data="{ generating: false }" x-on:mcp-token-generated.window="generating = false">
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.settings') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button variant="danger" icon="x" :disabled="! $passwordConfirmed" x-on:click="{{ $confirm(__($t.'.holder.revoke_all_title'), __($t.'.holder.revoke_all_body'), __($t.'.holder.revoke_all'), 'revokeAll') }}" data-revoke-all>{{ __($t.'.holder.revoke_all') }}</x-admin.button>
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

    {{-- Management chat (C26) --}}
    @unless ($managementConfigured)
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 sm:flex-row sm:items-center sm:justify-between" role="alert" data-management-missing>
            <p class="flex gap-2"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" /><span>{{ __($t.'.management_missing') }}@if ($isProduction) {{ __($t.'.management_missing_production') }}@endif</span></p>
            @can('settings.integrations')
                <x-admin.button size="sm" variant="secondary" icon="plug" :href="route('admin.settings.integrations')" wire:navigate>{{ __($t.'.integrations_link') }}</x-admin.button>
            @endcan
        </div>
    @endunless

    {{-- Tabs --}}
    <div class="mt-5 flex gap-1 border-b border-slate-200" role="tablist">
        @foreach (['access', 'calls'] as $key)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class([
                '-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold',
                'border-brand-600 text-brand-700' => $tab === $key,
                'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
            ]) data-tab="{{ $key }}">{{ __($t.'.tabs.'.$key) }}</button>
        @endforeach
    </div>

    @if ($tab === 'access')
        {{-- Holder --}}
        <x-admin.card :title="__($t.'.holder.title')" :description="__($t.'.holder.description')" class="mt-5">
            @if ($holder)
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-holder="{{ $holder->id }}">
                    <div>
                        <p class="font-semibold">{{ $holder->name }} <span class="font-normal text-slate-500">· {{ $holder->email }}</span></p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $holder->mcpAccessGrantedBy ? __($t.'.holder.granted', ['date' => $when($holder->mcp_access_granted_at), 'name' => $holder->mcpAccessGrantedBy->name]) : __($t.'.holder.granted_unknown', ['date' => $when($holder->mcp_access_granted_at)]) }}
                            · {{ __($t.'.calls.last_7_days', ['count' => $callsLast7Days]) }}
                        </p>
                    </div>
                    <x-admin.button variant="secondary" size="sm" icon="x" :disabled="! $passwordConfirmed" x-on:click="{{ $confirm(__($t.'.holder.revoke_title'), __($t.'.holder.revoke_body', ['name' => $holder->name]), __($t.'.holder.revoke'), 'revokeAccess') }}" data-revoke-access>{{ __($t.'.holder.revoke') }}</x-admin.button>
                </div>
            @else
                <p class="text-sm text-slate-500" data-no-holder>{{ __($t.'.holder.none') }}</p>
            @endif

            @php
                $candidates = $admins->reject(fn ($admin) => $holder && $admin->is($holder));
            @endphp
            <div class="mt-4 border-t border-slate-100 pt-4">
                @if ($candidates->isEmpty())
                    <p class="text-sm text-slate-500">{{ __($t.'.holder.no_admins') }}</p>
                @else
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="sm:w-80">
                            <x-admin.select wire:model="grantUserId" name="user" :label="__($t.'.holder.user')" :placeholder="__($t.'.holder.choose')" :options="$candidates->mapWithKeys(fn ($admin) => [$admin->id => $admin->name.' · '.$admin->email])->all()" />
                        </div>
                        @if ($holder)
                            <x-admin.button variant="secondary" icon="refund" :disabled="! $passwordConfirmed" x-on:click="{{ $confirm(__($t.'.holder.transfer_title'), __($t.'.holder.transfer_body', ['name' => $holder->name]), __($t.'.holder.transfer'), 'transfer') }}" data-transfer>{{ __($t.'.holder.transfer') }}</x-admin.button>
                        @else
                            <x-admin.button icon="check" wire:click="grant" loading="grant" :disabled="! $passwordConfirmed" data-grant>{{ __($t.'.holder.grant') }}</x-admin.button>
                        @endif
                    </div>
                @endif
            </div>
        </x-admin.card>

        {{-- Tokens --}}
        @if ($holder)
            <x-admin.card :title="__($t.'.tokens.title')" :description="__($t.'.tokens.description', ['max' => $maxTokens])" class="mt-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-slate-500">
                        @if ($activeTokens->count() >= $maxTokens)
                            {{ __($t.'.tokens.limit_reached', ['max' => $maxTokens]) }}
                        @endif
                    </p>
                    <x-admin.button icon="plus" x-on:click="generating = true" :disabled="! $passwordConfirmed || $activeTokens->count() >= $maxTokens" data-new-token>{{ __($t.'.tokens.generate') }}</x-admin.button>
                </div>
                @error('label')
                    <p class="mt-2 text-sm text-danger" data-generate-error>{{ $message }}</p>
                @enderror

                @if ($activeTokens->isEmpty())
                    <p class="mt-4 text-sm text-slate-500" data-no-tokens>{{ __($t.'.tokens.empty') }}</p>
                @else
                    <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200" data-token-list>
                        @foreach ($activeTokens as $token)
                            <li wire:key="mcp-token-{{ $token->id }}" class="flex flex-col gap-3 px-4 py-3.5 lg:flex-row lg:items-center" data-token="{{ $token->id }}">
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold">{{ $token->name }}</span>
                                        @if ($token->needsRotation())
                                            <x-admin.badge color="amber" title="{{ __($t.'.tokens.rotate_hint', ['days' => \App\Models\ApiToken::ROTATE_AFTER_DAYS]) }}" data-rotate>{{ __($t.'.tokens.rotate') }}</x-admin.badge>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $token->maskedToken() }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.tokens.idle_revoke_on', ['date' => $when($token->idleRevokeAt(), 'D MMM YYYY')]) }}</p>
                                </div>
                                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-600 sm:grid-cols-4 lg:w-[38rem]">
                                    <div><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.created') }}</dt><dd>{{ $when($token->created_at, 'D MMM YYYY') }}</dd></div>
                                    <div><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.last_used') }}</dt><dd>{{ $token->last_used_at ? $when($token->last_used_at) : __($t.'.tokens.never_used') }}</dd></div>
                                    <div><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.last_ip') }}</dt><dd class="font-mono">{{ $token->last_used_ip ?? __('admin.common.empty_value') }}</dd></div>
                                    <div><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.client') }}</dt><dd class="truncate" title="{{ $token->last_used_ua }}">{{ $token->last_used_ua ? \App\Support\Mcp\ClientFamily::of($token->last_used_ua) : __('admin.common.empty_value') }}</dd></div>
                                    <div class="col-span-2"><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.allowed_ips') }}</dt><dd class="font-mono">{{ $token->allowed_ips ? implode(', ', $token->allowed_ips) : __($t.'.tokens.any_ip') }}</dd></div>
                                    <div class="col-span-2"><dt class="font-semibold text-slate-500">{{ __($t.'.tokens.fields.idle_revoke_days') }}</dt><dd>{{ $token->idle_revoke_days ? __($t.'.tokens.idle_days', ['days' => $token->idle_revoke_days]) : __($t.'.tokens.idle_default', ['days' => \App\Models\ApiToken::DEFAULT_IDLE_REVOKE_DAYS]) }}</dd></div>
                                </dl>
                                <div class="flex shrink-0 flex-wrap gap-2 lg:w-44 lg:flex-col lg:items-end">
                                    <x-admin.button variant="ghost" size="sm" icon="sliders" wire:click="editToken({{ $token->id }})" data-edit-token="{{ $token->id }}">{{ __($t.'.tokens.settings') }}</x-admin.button>
                                    <x-admin.button variant="secondary" size="sm" icon="restore" :disabled="! $passwordConfirmed" x-on:click="{{ $confirm(__($t.'.tokens.regenerate_title'), __($t.'.tokens.regenerate_body', ['name' => $token->name]), __($t.'.tokens.regenerate'), 'regenerate', [$token->id]) }}" data-regenerate="{{ $token->id }}">{{ __($t.'.tokens.regenerate') }}</x-admin.button>
                                    <x-admin.button variant="secondary" size="sm" icon="x" :disabled="! $passwordConfirmed" x-on:click="{{ $confirm(__($t.'.tokens.revoke_title'), __($t.'.tokens.revoke_body', ['name' => $token->name]), __($t.'.tokens.revoke'), 'revokeToken', [$token->id]) }}" data-revoke-token="{{ $token->id }}">{{ __($t.'.tokens.revoke') }}</x-admin.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($revokedTokens->isNotEmpty())
                    <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.tokens.history') }}</h3>
                    <ul class="mt-2 space-y-1 text-xs text-slate-500" data-revoked-tokens>
                        @foreach ($revokedTokens as $token)
                            <li wire:key="mcp-revoked-{{ $token->id }}"><span class="font-semibold text-slate-600">{{ $token->name }}</span> <span class="font-mono">{{ $token->maskedToken() }}</span> · {{ __($t.'.tokens.revoked_on', ['date' => $when($token->revoked_at, 'D MMM YYYY'), 'reason' => $token->revoked_reason?->label() ?? __('admin.common.empty_value')]) }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        @endif
    @else
        {{-- Call log --}}
        <x-admin.card :title="__($t.'.calls.title')" :description="__($t.'.calls.description', ['days' => \App\Models\McpCall::KEEP_DAYS])" class="mt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="sm:w-56">
                    <x-admin.select wire:model.live="callTool" :label="__($t.'.calls.fields.tool')" :placeholder="__($t.'.calls.all_tools')" :options="collect($callTools)->mapWithKeys(fn ($tool) => [$tool => $tool === \App\Models\McpCall::REQUEST_TOOL ? __($t.'.calls.request') : $tool])->all()" />
                </div>
                <div class="sm:w-48">
                    <x-admin.select wire:model.live="callStatus" :label="__($t.'.calls.fields.status')" :placeholder="__($t.'.calls.all_statuses')" :options="collect(\App\Enums\McpCallStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()" />
                </div>
                <label class="flex h-10 items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model.live="writesOnly" class="size-4 rounded accent-brand-600">{{ __($t.'.calls.writes_only') }}
                </label>
            </div>

            <div class="mt-4" wire:loading.class="opacity-60">
                @if ($calls->isEmpty())
                    <x-admin.empty-state icon="list" :title="__($t.'.calls.empty')" />
                @else
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-left text-sm" data-call-log>
                            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.time') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.user') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.token') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.tool') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.status') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __($t.'.calls.fields.duration') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.ip') }}</th>
                                    <th class="px-3 py-2">{{ __($t.'.calls.fields.arguments') }}</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($calls as $call)
                                    <tr wire:key="mcp-call-{{ $call->id }}" data-call="{{ $call->id }}">
                                        <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ $when($call->created_at, 'D MMM, HH:mm:ss') }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $call->user?->name ?? __('admin.common.empty_value') }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $call->apiToken?->name ?? __('admin.common.empty_value') }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">
                                            {{ $call->tool === \App\Models\McpCall::REQUEST_TOOL ? __($t.'.calls.request') : $call->tool }}
                                            @if ($reason = $call->denyReason())
                                                <span class="block font-sans text-slate-500" data-deny-reason="{{ $reason->value }}">{{ $reason->label() }}</span>
                                            @endif
                                            @if ($call->is_publish)
                                                <x-admin.badge color="violet">{{ __($t.'.calls.publish') }}</x-admin.badge>
                                            @elseif ($call->is_write)
                                                <x-admin.badge color="sky">{{ __($t.'.calls.write') }}</x-admin.badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2"><x-admin.badge :color="$call->status->color()">{{ $call->status->label() }}</x-admin.badge></td>
                                        <td class="px-3 py-2 text-right text-xs tabular-nums">{{ $call->duration_ms }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $call->ip ?? __('admin.common.empty_value') }}</td>
                                        <td class="max-w-xs truncate px-3 py-2 font-mono text-xs text-slate-500" title="{{ json_encode($call->arguments, JSON_UNESCAPED_UNICODE) }}">{{ $call->arguments ? \Illuminate\Support\Str::limit(json_encode($call->arguments, JSON_UNESCAPED_UNICODE), 80) : '' }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @if ($call->before !== null)
                                                <x-admin.button variant="ghost" size="sm" icon="eye" wire:click="showBefore({{ $call->id }})" data-view-before="{{ $call->id }}">{{ __($t.'.calls.view_before') }}</x-admin.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $calls->links() }}</div>
                @endif
            </div>
        </x-admin.card>
    @endif

    {{-- Show once (C4) --}}
    @if ($revealedToken)
        @php
            $desktopConfig = json_encode(['mcpServers' => ['poson' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote@'.$mcpRemoteVersion, $endpoint, '--header', 'Authorization:${POSON_MCP_AUTH}'],
                'env' => ['POSON_MCP_AUTH' => 'Bearer '.$revealedToken],
            ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            // C15: the token is typed in by `read -rs` (no echo, not in the shell history), never part of the command.
            $codeCommand = 'read -rs POSON_MCP_TOKEN && claude mcp add --transport http --scope user poson '.$endpoint.' --header "Authorization: Bearer $POSON_MCP_TOKEN"; unset POSON_MCP_TOKEN';
        @endphp
        <div x-data="{ open: true, copied: null }" x-show="open" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="mcp-token-title" data-token-revealed>
            <div class="absolute inset-0 bg-slate-900/50"></div>
            <div x-trap.noscroll="open" class="relative max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-2xl sm:rounded-2xl sm:pb-5">
                <h2 id="mcp-token-title" class="text-base font-semibold">{{ __($t.'.reveal.title', ['name' => $revealedLabel]) }}</h2>
                <p class="mt-1 flex gap-2 text-sm text-amber-800"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.reveal.warning') }}</p>

                @foreach ([
                    ['key' => 'token', 'title' => null, 'hint' => null, 'value' => $revealedToken],
                    ['key' => 'desktop', 'title' => __($t.'.reveal.desktop'), 'hint' => __($t.'.reveal.desktop_hint'), 'value' => $desktopConfig],
                    ['key' => 'code', 'title' => __($t.'.reveal.code'), 'hint' => __($t.'.reveal.code_hint'), 'value' => $codeCommand],
                ] as $block)
                    <div class="mt-4">
                        @if ($block['title'])
                            <h3 class="text-sm font-semibold">{{ $block['title'] }}</h3>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $block['hint'] }}</p>
                        @endif
                        <div class="mt-2 flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <pre class="min-w-0 flex-1 overflow-x-auto whitespace-pre-wrap break-all px-1 font-mono text-xs text-slate-800" data-snippet="{{ $block['key'] }}" @if ($block['key'] === 'token') data-plain-token @endif>{{ $block['value'] }}</pre>
                            <button type="button" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-white px-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50" x-on:click="navigator.clipboard?.writeText(@js($block['value'])); copied = '{{ $block['key'] }}'; setTimeout(() => copied = null, 1500)">
                                <x-admin.icon name="copy" size="size-4" />
                                <span x-show="copied !== '{{ $block['key'] }}'">{{ __($t.'.reveal.copy') }}</span>
                                <span x-cloak x-show="copied === '{{ $block['key'] }}'">{{ __($t.'.reveal.copied') }}</span>
                            </button>
                        </div>
                    </div>
                @endforeach

                <div class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                    <p class="font-semibold text-slate-700">{{ __($t.'.reveal.rules_title') }}</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                        @foreach (__($t.'.reveal.rules') as $rule)
                            <li>{{ $rule }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-admin.button x-on:click="open = false">{{ __($t.'.reveal.done') }}</x-admin.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Generate --}}
    <div x-cloak x-show="generating" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="mcp-generate-title" x-on:keydown.escape.window="generating = false">
        <div x-show="generating" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="generating = false"></div>
        <form wire:submit="generate" x-show="generating" x-transition x-trap.noscroll="generating" novalidate class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5" data-generate-token>
            <h2 id="mcp-generate-title" class="text-base font-semibold">{{ __($t.'.tokens.generate_title') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __($t.'.tokens.generate_body') }}</p>
            <div class="mt-4 space-y-4">
                <x-admin.input wire:model="label" :label="__($t.'.tokens.fields.label')" :hint="__($t.'.tokens.label_hint')" required maxlength="100" />
                <x-admin.input wire:model="idleRevokeDays" name="idle_revoke_days" type="number" :min="\App\Models\ApiToken::MIN_IDLE_REVOKE_DAYS" :max="\App\Models\ApiToken::MAX_IDLE_REVOKE_DAYS" :label="__($t.'.tokens.fields.idle_revoke_days')" :hint="__($t.'.tokens.idle_hint', ['min' => \App\Models\ApiToken::MIN_IDLE_REVOKE_DAYS, 'max' => \App\Models\ApiToken::MAX_IDLE_REVOKE_DAYS, 'default' => \App\Models\ApiToken::DEFAULT_IDLE_REVOKE_DAYS])" />
                <x-admin.textarea wire:model="allowedIps" name="allowed_ips" rows="3" :label="__($t.'.tokens.fields.allowed_ips')" :hint="__($t.'.tokens.ips_hint')" />
            </div>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="generating = false" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                <x-admin.button type="submit" loading="generate">{{ __($t.'.tokens.generate') }}</x-admin.button>
            </div>
        </form>
    </div>

    {{-- Token settings --}}
    @if ($editingToken)
        <div class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="mcp-settings-title" x-data x-on:keydown.escape.window="$wire.cancelEdit()" data-token-settings>
            <div class="absolute inset-0 bg-slate-900/50" wire:click="cancelEdit"></div>
            <form wire:submit="saveTokenSettings" novalidate class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5">
                <h2 id="mcp-settings-title" class="text-base font-semibold">{{ __($t.'.tokens.settings_title') }} · {{ $editingToken->name }}</h2>
                <div class="mt-4 space-y-4">
                    <x-admin.input wire:model="editIdleRevokeDays" name="edit_idle_revoke_days" type="number" :label="__($t.'.tokens.fields.idle_revoke_days')" :hint="__($t.'.tokens.idle_hint', ['min' => \App\Models\ApiToken::MIN_IDLE_REVOKE_DAYS, 'max' => \App\Models\ApiToken::MAX_IDLE_REVOKE_DAYS, 'default' => \App\Models\ApiToken::DEFAULT_IDLE_REVOKE_DAYS])" />
                    <x-admin.textarea wire:model="editAllowedIps" name="edit_allowed_ips" rows="3" :label="__($t.'.tokens.fields.allowed_ips')" :hint="__($t.'.tokens.ips_hint')" />
                </div>
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="cancelEdit" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                    <x-admin.button type="submit" loading="saveTokenSettings" :disabled="! $passwordConfirmed">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </form>
        </div>
    @endif

    {{-- View before (C22) --}}
    @if ($viewingCall)
        <div class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="mcp-before-title" x-data x-on:keydown.escape.window="$wire.closeBefore()" data-before-view>
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeBefore"></div>
            <div class="relative max-h-[85vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:max-w-2xl sm:rounded-2xl">
                <h2 id="mcp-before-title" class="text-base font-semibold">{{ __($t.'.calls.before_title') }} · <span class="font-mono text-sm">{{ $viewingCall->tool }}</span></h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ $when($viewingCall->created_at) }}</p>
                <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-800">{{ json_encode($viewingCall->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                <div class="mt-6 flex justify-end">
                    <x-admin.button variant="secondary" wire:click="closeBefore">{{ __($t.'.calls.close') }}</x-admin.button>
                </div>
            </div>
        </div>
    @endif
</div>
