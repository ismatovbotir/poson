@php($t = 'office.settings')

{{-- Virtual office → Settings (plan 41 §8.5). Admin only; switches default off; nothing is ever sent to Telegram in phase 1. --}}
<form wire:submit="save" novalidate data-office-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 pb-24 lg:space-y-6">
        <x-admin.card :title="__($t.'.switches')" :description="__($t.'.switches_help')">
            <div class="space-y-4">
                <x-admin.toggle wire:model="ingestEnabled" :label="__($t.'.ingest_enabled')" :description="__($t.'.ingest_enabled_hint')" />
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5 text-sm">
                    <span class="font-semibold">{{ __($t.'.ai_key') }}</span>
                    <x-admin.badge :color="$keyConfigured ? 'brand' : 'amber'" pill dot data-ai-key="{{ $keyConfigured ? 'configured' : 'missing' }}">{{ $keyConfigured ? __($t.'.ai_key_configured') : __($t.'.ai_key_missing') }}</x-admin.badge>
                </div>
                <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.privacy_note') }}</p>
                <x-admin.toggle wire:model="aiEnabled" :label="__($t.'.ai_enabled')" :description="__($t.'.ai_enabled_hint')" />
                <x-admin.toggle wire:model="autoLead" :label="__($t.'.auto_lead')" :description="__($t.'.auto_lead_hint')" />
                @error('ai_enabled')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.owners')" :description="__($t.'.owners_help')">
            <div class="space-y-3">
                @if ($ownersLocked)
                    <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" data-owners-locked>{{ __($t.'.owners_locked') }}</p>
                @endif
                <x-admin.input wire:model="ownerIds" name="owner_ids" :label="__($t.'.owner_ids')" :hint="__($t.'.owner_ids_hint')" inputmode="numeric" maxlength="300" :disabled="$ownersLocked" />
                @unless ($passwordConfirmed || $ownersLocked)
                    <p class="text-xs text-slate-500">{{ __($t.'.owners_password') }} <button type="button" wire:click="confirmPassword" class="font-semibold text-brand-700 hover:underline">{{ __($t.'.confirm_password') }}</button></p>
                @endunless
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.limits')" :description="__($t.'.limits_help')">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model="debounceMinutes" name="debounce_minutes" type="number" min="{{ config('office.debounce_min') }}" max="{{ config('office.debounce_max') }}" :label="__($t.'.debounce')" :hint="__($t.'.debounce_hint')" />
                    <x-admin.input wire:model="perChatDaily" name="per_chat_daily" type="number" min="1" max="{{ config('office.per_chat_daily_max') }}" :label="__($t.'.per_chat_daily')" :hint="__($t.'.per_chat_daily_hint')" />
                </div>
                @foreach ($channels as $channel)
                    <div wire:key="cap-{{ $channel->value }}" class="grid gap-4 sm:grid-cols-2">
                        <x-admin.input wire:model="caps.{{ $channel->value }}.requests" name="caps.{{ $channel->value }}.requests" type="number" min="0" max="{{ $maxCaps[$channel->value]['requests'] }}" :label="__($t.'.cap_requests', ['channel' => $channel->label()])" :hint="__($t.'.cap_max', ['max' => number_format($maxCaps[$channel->value]['requests'], 0, '.', ' ')])" />
                        <x-admin.input wire:model="caps.{{ $channel->value }}.tokens" name="caps.{{ $channel->value }}.tokens" type="number" min="0" max="{{ $maxCaps[$channel->value]['tokens'] }}" :label="__($t.'.cap_tokens', ['channel' => $channel->label()])" :hint="__($t.'.cap_max', ['max' => number_format($maxCaps[$channel->value]['tokens'], 0, '.', ' ')])" />
                    </div>
                @endforeach
                <p class="text-xs text-slate-500">{{ __($t.'.usage_hint') }}</p>
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.today')" :description="__($t.'.today_help')" data-cap-state>
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                @foreach ($channels as $channel)
                    @php($row = $usage->get($channel->value))
                    @php($cap = $activeCaps[$channel->value])
                    @php($reached = $row && ($row->requests >= $cap['requests'] || $row->billed_tokens + $row->reserved_tokens >= $cap['tokens']))
                    <div wire:key="usage-{{ $channel->value }}" class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2" data-usage="{{ $channel->value }}">
                        <dt class="text-slate-600">{{ $channel->label() }}</dt>
                        <dd class="flex items-center gap-2 tabular-nums">
                            {{ __($t.'.usage_value', ['requests' => number_format($row?->requests ?? 0, 0, '.', ' '), 'cap' => number_format($cap['requests'], 0, '.', ' ')]) }}
                            @if ($reached)<x-admin.badge color="amber" data-cap-reached>{{ __($t.'.cap_reached') }}</x-admin.badge>@endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-3 text-sm text-slate-600">{{ __($t.'.allowed_updates_hint') }} <a href="{{ route('admin.telegram.bot') }}" wire:navigate class="font-semibold text-brand-700 hover:underline" data-bot-link>{{ __($t.'.allowed_updates_link') }}</a></p>
        </x-admin.card>

        <x-admin.card :title="__($t.'.connections')" :description="__($t.'.connections_help')" data-connections>
            @forelse ($connections as $connection)
                <div wire:key="conn-{{ $connection->id }}" class="flex flex-wrap items-center gap-2 border-b border-slate-100 py-2 text-sm last:border-0">
                    <x-admin.badge :color="$connection->is_enabled ? 'brand' : 'muted'" pill dot>{{ $connection->is_enabled ? __($t.'.connected') : __($t.'.disconnected') }}</x-admin.badge>
                    <x-admin.badge :color="$connection->is_allowed ? 'brand' : 'red'">{{ $connection->is_allowed ? __($t.'.allowed') : __($t.'.not_allowed') }}</x-admin.badge>
                    <span class="text-slate-500">{{ $connection->can_reply ? __($t.'.can_reply') : __($t.'.read_only') }}</span>
                    <span class="ml-auto text-xs text-slate-500">{{ ($connection->connected_at ?? $connection->updated_at)?->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ __($t.'.no_connections') }}</p>
            @endforelse
        </x-admin.card>

        <x-admin.card :title="__($t.'.audit')">
            @forelse ($audits as $audit)
                <p wire:key="audit-{{ $audit->id }}" class="border-b border-slate-100 py-1.5 text-xs text-slate-600 last:border-0">
                    {{ $audit->created_at?->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }} · {{ $audit->user?->name ?? __($t.'.system') }} · <code>{{ $audit->key }}</code> · {{ $audit->action->label() }}
                </p>
            @empty
                <p class="text-sm text-slate-500">{{ __($t.'.no_audit') }}</p>
            @endforelse
        </x-admin.card>

        <x-admin.card :title="__($t.'.wipe')" :description="__($t.'.wipe_help')" class="border-red-200">
            @error('wipe')<p class="mb-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
            <x-admin.button variant="danger" icon="trash" wire:click="wipe" wire:confirm="{{ __($t.'.wipe_confirm') }}" loading="wipe" data-action="wipe">{{ __($t.'.wipe_button') }}</x-admin.button>
        </x-admin.card>
    </div>
</form>
