@php($t = 'admin.telegram_bot')

{{-- Telegram → Bot (plan 14 v2 §9). Admin only; every write needs a recent password confirmation and is audited. --}}
<div class="pb-10" wire:init="refresh" data-telegram-bot>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.telegram') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="restore" wire:click="refresh" loading="refresh">{{ __($t.'.refresh') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 lg:space-y-6">
        @unless ($passwordConfirmed)
            <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-password-required>
                <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.password_hint') }}
            </p>
        @endunless

        @if ($loadError !== null)
            <p class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-load-error>
                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.errors.telegram_error', ['code' => $loadError]) }}
            </p>
        @endif

        @foreach ($problems as $event => $since)
            <p wire:key="problem-{{ $event }}" class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-problem="{{ $event }}">
                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />
                <span>{{ __('telegram.monitoring.events.'.$event) }} · {{ $since->timezone(config('app.timezone'))->format('d.m.Y H:i') }}. {{ __($t.'.problem_hints.'.$event) }}
                    @if ($event === 'telegram_token')
                        @can('settings.integrations')
                            <a href="{{ route('admin.telegram.settings') }}" wire:navigate class="font-semibold underline hover:no-underline" data-settings-link>{{ __('admin.telegram_settings.open') }}</a>
                        @endcan
                    @endif
                </span>
            </p>
        @endforeach

        {{-- Bot identity and the kill switch --}}
        <x-admin.card :title="__($t.'.bot_card')" :description="__($t.'.bot_card_hint')">
            <x-slot:actions>
                <x-admin.badge :color="$enabled ? 'brand' : 'amber'" dot data-bot-state="{{ $enabled ? 'enabled' : 'disabled' }}">
                    {{ $enabled ? __($t.'.state_enabled') : __($t.'.state_disabled') }}
                </x-admin.badge>
            </x-slot:actions>

            @unless ($loaded)
                <div class="grid animate-pulse gap-3 sm:grid-cols-3" data-loading-skeleton aria-busy="true">
                    <div class="h-10 rounded-lg bg-slate-100"></div>
                    <div class="h-10 rounded-lg bg-slate-100"></div>
                    <div class="h-10 rounded-lg bg-slate-100"></div>
                    <span class="sr-only">{{ __($t.'.loading') }}</span>
                </div>
            @else
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500">{{ __($t.'.username') }}</dt>
                    <dd class="mt-0.5 font-medium">{{ $bot['username'] ?? null ? '@'.$bot['username'] : __('admin.common.empty_value') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.bot_id') }}</dt>
                    <dd class="mt-0.5 font-mono">{{ $bot['id'] ?? __('admin.common.empty_value') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.privacy_mode') }}</dt>
                    <dd class="mt-0.5">
                        @if (($bot['privacy'] ?? null) === null)
                            {{ __('admin.common.empty_value') }}
                        @elseif ($bot['privacy'])
                            <x-admin.badge color="brand">{{ __($t.'.privacy_on') }}</x-admin.badge>
                        @else
                            <x-admin.badge color="red">{{ __($t.'.privacy_off') }}</x-admin.badge>
                        @endif
                    </dd>
                </div>
            </dl>
            @endunless

            <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                @if ($enabled)
                    <x-admin.button variant="danger" icon="x" wire:click="disable" wire:confirm="{{ __($t.'.confirm_disable') }}" loading="disable" data-action="disable">{{ __($t.'.disable') }}</x-admin.button>
                    <p class="text-sm text-slate-500">{{ __($t.'.disable_hint') }}</p>
                @else
                    <x-admin.button icon="check" wire:click="enable" loading="enable" :disabled="! $ready" data-action="enable">{{ __($t.'.enable') }}</x-admin.button>
                    <p class="text-sm text-slate-500">{{ $ready ? __($t.'.enable_hint') : __($t.'.enable_blocked') }}</p>
                @endif
            </div>
        </x-admin.card>

        {{-- Readiness checklist (re-checked on the server when enabling) --}}
        <x-admin.card :title="__($t.'.checklist_title')" :description="__($t.'.checklist_hint')">
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($checklist as $item => $ok)
                    <li wire:key="check-{{ $item }}" class="flex items-start gap-3 py-2.5" data-check="{{ $item }}" data-ok="{{ $ok === null ? '' : ($ok ? '1' : '0') }}">
                        <span @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', 'bg-brand-50 text-brand-700' => $ok === true, 'bg-red-50 text-danger' => $ok === false, 'animate-pulse bg-slate-100 text-slate-400' => $ok === null])>
                            <x-admin.icon :name="$ok === null ? 'clock' : ($ok ? 'check' : 'x')" size="size-3.5" />
                        </span>
                        <span class="min-w-0">
                            <span class="font-medium">{{ __($t.'.checks.'.$item) }}</span>
                            @if ($ok === null)
                                <span class="mt-0.5 block text-slate-500">{{ __($t.'.checking') }}</span>
                            @elseif (! $ok)
                                <span class="mt-0.5 block text-slate-500">{{ __($t.'.checks_fix.'.$item) }}</span>
                                @if (in_array($item, ['token', 'webhook_secret', 'chat'], true))
                                    @can('settings.integrations')
                                        <a href="{{ route('admin.telegram.settings') }}" wire:navigate class="mt-0.5 block font-semibold text-brand-700 hover:underline" data-settings-link>{{ __('admin.telegram_settings.open') }}</a>
                                    @endcan
                                @endif
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>

        {{-- Webhook --}}
        <x-admin.card :title="__($t.'.webhook_title')" :description="__($t.'.webhook_hint')">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <dt class="text-slate-500">{{ __($t.'.expected_url') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs">{{ $expectedUrl ?? __($t.'.no_https') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.webhook_state') }}</dt>
                    <dd class="mt-0.5" data-webhook-state="{{ $webhook === null ? 'unknown' : ($webhook['matches'] ? 'matches' : ($webhook['set'] ? 'different' : 'none')) }}">
                        @if ($webhook === null)
                            {{ __('admin.common.empty_value') }}
                        @elseif ($webhook['matches'])
                            <x-admin.badge color="brand">{{ __($t.'.webhook_matches') }}</x-admin.badge>
                        @elseif ($webhook['set'])
                            <x-admin.badge color="red">{{ __($t.'.webhook_different') }}</x-admin.badge>
                        @else
                            <x-admin.badge color="amber">{{ __($t.'.webhook_none') }}</x-admin.badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.pending') }}</dt>
                    <dd class="mt-0.5 font-medium">{{ $webhook['pending'] ?? __('admin.common.empty_value') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.last_error') }}</dt>
                    <dd class="mt-0.5">{{ ($webhook['last_error_at'] ?? null) ? \Illuminate\Support\Carbon::createFromTimestamp($webhook['last_error_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : __($t.'.no_errors') }}</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-100 pt-4">
                <x-admin.button icon="link" wire:click="setWebhook" loading="setWebhook" data-action="set-webhook">{{ __($t.'.set_webhook') }}</x-admin.button>
                <x-admin.button variant="secondary" icon="trash" wire:click="deleteWebhook" wire:confirm="{{ __($t.'.confirm_delete_webhook') }}" loading="deleteWebhook" data-action="delete-webhook">{{ __($t.'.delete_webhook') }}</x-admin.button>
            </div>
        </x-admin.card>

        {{-- Audit --}}
        <x-admin.card :title="__($t.'.audit_title')">
            @if ($audits->isEmpty())
                <p class="text-sm text-slate-500">{{ __($t.'.audit_empty') }}</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($audits as $audit)
                        <li wire:key="bot-audit-{{ $audit->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2" data-audit-row>
                            <span class="flex items-center gap-2">
                                <x-admin.badge>{{ $audit->action->label() }}</x-admin.badge>
                                <span class="font-mono text-xs text-slate-500">{{ $audit->key }}</span>
                            </span>
                            <span class="text-xs text-slate-500">{{ $audit->user?->name ?? __($t.'.console') }} · {{ $audit->created_at->format('d.m.Y H:i') }} · <span class="font-mono">{{ $audit->ip ?? __('admin.common.empty_value') }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>
    </div>
</div>
