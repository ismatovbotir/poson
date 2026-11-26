@php($t = 'office.settings')

{{-- Virtual office → Settings (plan 41 §8.5). Admin only; switches default off; nothing is ever sent to Telegram in phase 1. --}}
<form wire:submit="save" novalidate data-office-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid items-start gap-4 pb-24 lg:gap-6 xl:grid-cols-2">
        <x-admin.card :title="__($t.'.switches')" :description="__($t.'.switches_help')">
            <div class="space-y-4">
                <x-admin.toggle wire:model="ingestEnabled" :label="__($t.'.ingest_enabled')" :description="__($t.'.ingest_enabled_hint')" />
                <x-admin.toggle wire:model="groupsEnabled" :label="__($t.'.groups_enabled')" :description="__($t.'.groups_enabled_hint')" />
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

        <x-admin.card :title="__($t.'.owners')" :description="__($t.'.owners_help')" data-owner-picker>
            @php($sourceLabel = fn (?string $source): string => $source === null ? __($t.'.owner_source_unknown') : __($t.'.owner_source_'.$source))
            <div class="space-y-3">
                @if ($ownersLocked)
                    <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" data-owners-locked>{{ __($t.'.owners_locked') }} {{ __($t.'.owners_locked_hint') }}</p>
                @endif

                <ul class="flex flex-wrap gap-2" aria-label="{{ __($t.'.owner_ids') }}" data-owner-chips>
                    @forelse ($owners as $owner)
                        @php($ownerName = $owner['first_name'] ?? $owner['username'])
                        <li wire:key="owner-{{ $owner['telegram_id'] }}" class="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-brand-50 py-1 pl-2.5 pr-1 text-sm text-brand-800" data-owner-chip="{{ $owner['telegram_id'] }}">
                            <span class="truncate font-semibold">{{ $ownerName !== null ? $ownerName : __($t.'.owner_unknown', ['id' => $owner['telegram_id']]) }}</span>
                            @if ($ownerName !== null)
                                @if ($owner['username'])<span class="truncate text-brand-700">{{ '@'.$owner['username'] }}</span>@endif
                                <span class="tabular-nums text-xs text-brand-700">#{{ $owner['telegram_id'] }}</span>
                            @endif
                            @unless ($ownersLocked)
                                <button type="button" wire:click="removeOwner({{ $owner['telegram_id'] }})" wire:confirm="{{ __($t.'.owner_remove_confirm', ['id' => $owner['telegram_id'], 'source' => $sourceLabel($owner['source'])]) }}" class="grid size-6 place-items-center rounded hover:bg-brand-100" aria-label="{{ __($t.'.owner_remove') }}: #{{ $owner['telegram_id'] }}" data-owner-remove="{{ $owner['telegram_id'] }}"><x-admin.icon name="x" size="size-3.5" /></button>
                            @else
                                <span class="sr-only">{{ $sourceLabel($owner['source']) }}</span>
                            @endunless
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">{{ __($t.'.owners_empty') }}</li>
                    @endforelse
                </ul>

                @error('owner_ids')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror

                @unless ($ownersLocked)
                    @if ($canAddOwner)
                        <div class="relative">
                            <x-admin.input
                                id="owner-search"
                                type="search"
                                icon="search"
                                wire:model.live.debounce.300ms="ownerSearch"
                                name="ownerSearch"
                                :label="__($t.'.owner_search')"
                                :hint="__($t.'.owner_search_hint')"
                                :placeholder="__($t.'.owner_search_placeholder')"
                                x-on:keydown.enter.prevent=""
                                maxlength="64"
                                autocomplete="off"
                                aria-controls="owner-candidates"
                                data-owner-search
                            />

                            @php($searching = mb_strlen(trim($ownerSearch)) >= \App\Support\Office\OwnerCandidates::MIN_TERM)
                            <p class="sr-only" aria-live="polite" data-owner-results-count>{{ $searching ? trans_choice($t.'.owner_results', count($candidates), ['count' => count($candidates)]) : '' }}</p>
                            @if ($searching)
                                <ul id="owner-candidates" aria-label="{{ __($t.'.owner_results_label') }}" class="mt-2 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" data-owner-candidates>
                                    @forelse ($candidates as $candidate)
                                        <li wire:key="cand-{{ $candidate['telegram_id'] }}">
                                            <button
                                                type="button"
                                                wire:click="addOwner({{ $candidate['telegram_id'] }})"
                                                wire:confirm="{{ __($t.'.owner_add_confirm', ['id' => $candidate['telegram_id'], 'source' => $sourceLabel($candidate['source'])]) }}"
                                                class="flex w-full flex-wrap items-center gap-x-2 gap-y-0.5 px-3 py-2 text-left text-sm hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                                                data-owner-candidate="{{ $candidate['telegram_id'] }}"
                                            >
                                                <span class="font-semibold">{{ $candidate['first_name'] ?? __($t.'.owner_no_name') }}</span>
                                                @if ($candidate['username'])<span class="text-slate-600">{{ '@'.$candidate['username'] }}</span>@endif
                                                <span class="tabular-nums text-xs text-slate-500">#{{ $candidate['telegram_id'] }}</span>
                                                <x-admin.badge :color="$candidate['source'] === \App\Support\Office\OwnerCandidates::SOURCE_CONNECTION ? 'brand' : 'muted'">{{ $sourceLabel($candidate['source']) }}</x-admin.badge>
                                                <span class="ml-auto text-xs text-slate-500">{{ $candidate['last_seen'] ? __($t.'.owner_last_seen', ['date' => $candidate['last_seen']->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i')]) : '' }}</span>
                                            </button>
                                        </li>
                                    @empty
                                        <li class="px-3 py-2 text-sm text-slate-500">{{ __($t.'.owner_no_results') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>

                        <details class="rounded-lg border border-slate-200 px-3 py-2 text-sm" data-owner-manual>
                            <summary class="cursor-pointer font-semibold text-slate-700">{{ __($t.'.owner_manual') }}</summary>
                            <div class="mt-2 space-y-2" x-data="{ template: @js(__($t.'.owner_add_confirm', ['id' => '__ID__', 'source' => __($t.'.owner_source_manual')])), invalid: @js(__($t.'.owner_id_invalid')), error: '' }">
                                <p class="text-xs text-slate-500">{{ __($t.'.owner_manual_hint') }}</p>
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-admin.input wire:model="manualOwnerId" name="manualOwnerId" :label="__($t.'.owner_id')" inputmode="numeric" maxlength="16" class="min-w-0 flex-1" x-on:keydown.enter.prevent="" />
                                    <x-admin.button variant="secondary" icon="plus" loading="addManualOwner" x-on:click="const id = String($wire.manualOwnerId ?? '').trim(); error = /^[1-9][0-9]{0,15}$/.test(id) ? '' : invalid; if (! error && confirm(template.replace('__ID__', id))) { $wire.addManualOwner() }" data-owner-manual-add>{{ __($t.'.owner_add') }}</x-admin.button>
                                </div>
                                <p x-show="error" x-text="error" x-cloak class="text-sm text-danger" role="alert" data-owner-manual-error></p>
                            </div>
                        </details>
                    @else
                        <p class="text-xs text-slate-500">{{ __($t.'.owners_max', ['max' => \App\Actions\Office\SetOfficeOwners::MAX_IDS]) }}</p>
                    @endif

                    @unless ($passwordConfirmed)
                        <p class="text-xs text-slate-500">{{ __($t.'.owners_password') }} <button type="button" wire:click="confirmPassword" class="font-semibold text-brand-700 hover:underline">{{ __($t.'.confirm_password') }}</button></p>
                    @endunless
                @endunless

                <p class="flex items-start gap-2 text-xs text-slate-500" data-owners-lost-hint><x-admin.icon name="alert" size="size-3.5" class="mt-0.5 shrink-0" />{{ __($t.'.owners_lost_hint') }}</p>
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
                @php($isOwner = in_array($connection->owner_telegram_id, $ownerIdList, true))
                <div wire:key="conn-{{ $connection->id }}" class="flex flex-wrap items-center gap-2 border-b border-slate-100 py-2 text-sm last:border-0" data-connection="{{ $connection->id }}">
                    <x-admin.badge :color="$connection->is_enabled ? 'brand' : 'muted'" pill dot>{{ $connection->is_enabled ? __($t.'.connected') : __($t.'.disconnected') }}</x-admin.badge>
                    <x-admin.badge :color="$connection->is_allowed ? 'brand' : 'red'">{{ $connection->is_allowed ? __($t.'.allowed') : __($t.'.not_allowed') }}</x-admin.badge>
                    <span class="font-semibold">{{ $connection->owner_first_name ?? __($t.'.owner_no_name') }}</span>
                    @if ($connection->owner_username)<span class="text-slate-600">{{ '@'.$connection->owner_username }}</span>@endif
                    <span class="tabular-nums text-xs text-slate-500">#{{ $connection->owner_telegram_id }}</span>
                    <span class="text-slate-500">{{ $connection->can_reply ? __($t.'.can_reply') : __($t.'.read_only') }}</span>
                    <span class="ml-auto text-xs text-slate-500">{{ ($connection->connected_at ?? $connection->updated_at)?->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                    @unless ($ownersLocked)
                        @if ($isOwner)
                            <x-admin.button size="sm" variant="ghost" icon="x" wire:click="removeConnection({{ $connection->id }})" wire:confirm="{{ __($t.'.owner_remove_confirm', ['id' => $connection->owner_telegram_id, 'source' => __($t.'.owner_source_connection')]) }}" loading="removeConnection" data-connection-remove="{{ $connection->id }}">{{ __($t.'.owner_remove') }}</x-admin.button>
                        @elseif ($canAddOwner)
                            <x-admin.button size="sm" variant="soft" icon="check" wire:click="allowConnection({{ $connection->id }})" wire:confirm="{{ __($t.'.owner_add_confirm', ['id' => $connection->owner_telegram_id, 'source' => __($t.'.owner_source_connection')]) }}" loading="allowConnection" data-connection-allow="{{ $connection->id }}">{{ __($t.'.owner_allow') }}</x-admin.button>
                        @endif
                    @endunless
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ __($t.'.no_connections') }}</p>
            @endforelse
            @if ($connections->contains('is_allowed', false))
                <p class="mt-3 text-xs text-slate-500">{{ $ownersLocked ? __($t.'.owners_locked_hint') : __($t.'.owners_lost_hint') }}</p>
            @endif
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
