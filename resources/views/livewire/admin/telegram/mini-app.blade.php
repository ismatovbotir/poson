@php($t = 'admin.telegram_mini_app')

{{-- Telegram → Mini App (plan 20 §10, M6). Admin only; every write needs a recent password confirmation, is rate-limited and audited. No Telegram call on load. --}}
<div class="pb-10" data-telegram-mini-app>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.telegram') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="restore" wire:click="checkBot" loading="checkBot" data-action="check-bot">{{ __($t.'.check_bot') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 space-y-4 lg:space-y-6">
        @unless ($passwordConfirmed)
            <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-password-required>
                <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.password_hint') }}
            </p>
        @endunless

        {{-- Status line: username and menu sync state (never a token, getMe response or Telegram id, MA-39) --}}
        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700" data-status-line>
            <span class="inline-flex items-center gap-1.5"><x-admin.icon name="bot" size="size-4" class="shrink-0 text-slate-400" />{{ __($t.'.username') }}:
                <span class="font-medium" data-username>{{ $username !== null ? '@'.$username : __($t.'.username_missing') }}</span></span>
            <span class="inline-flex items-center gap-1.5"><x-admin.icon name="smartphone" size="size-4" class="shrink-0 text-slate-400" />{{ __($t.'.sync.label') }}:
                <span class="font-medium" data-sync-state="{{ $sync['state'] ?? 'never' }}">{{ __($t.'.sync.states.'.($sync['state'] ?? 'never')) }}</span></span>
        </p>

        {{-- The switch --}}
        <x-admin.card :title="__($t.'.switch_card')" :description="__($t.'.switch_card_hint')">
            <x-slot:actions>
                <x-admin.badge :color="$enabled ? 'brand' : 'amber'" dot data-mini-app-state="{{ $enabled ? 'enabled' : 'disabled' }}">
                    {{ $enabled ? __($t.'.state_enabled') : __($t.'.state_disabled') }}
                </x-admin.badge>
            </x-slot:actions>

            @if ($storedOn && ! $botOn)
                <p class="mb-4 flex gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="status" data-bot-off>
                    <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.bot_off_hint') }}
                </p>
            @endif

            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">{{ __($t.'.orders_30') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums" data-orders-30>{{ $orders }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.cart_mode') }}</dt>
                    <dd class="mt-0.5" data-cart-enabled="{{ $info['cart_enabled'] ? '1' : '0' }}">{{ $info['cart_enabled'] ? __($t.'.cart_on') : __($t.'.cart_off') }}</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                @if ($storedOn)
                    <x-admin.button variant="danger" icon="x" wire:click="disable" wire:confirm="{{ __($t.'.confirm_disable') }}" loading="disable" data-action="disable">{{ __($t.'.disable') }}</x-admin.button>
                    <p class="text-sm text-slate-500">{{ __($t.'.disable_hint') }}</p>
                @else
                    <x-admin.button icon="check" wire:click="enable" loading="enable" :disabled="! $ready" data-action="enable">{{ __($t.'.enable') }}</x-admin.button>
                    <p class="text-sm text-slate-500">{{ $ready ? __($t.'.enable_hint') : __($t.'.enable_blocked') }}</p>
                @endif
            </div>
        </x-admin.card>

        {{-- Checklist (re-checked on the server when enabling) --}}
        <x-admin.card :title="__($t.'.checklist_title')" :description="__($t.'.checklist_hint')">
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($checklist as $item => $ok)
                    <li wire:key="mini-app-check-{{ $item }}" class="flex items-start gap-3 py-2.5" data-check="{{ $item }}" data-ok="{{ $ok ? '1' : '0' }}">
                        <span @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', 'bg-brand-50 text-brand-700' => $ok, 'bg-red-50 text-danger' => ! $ok])>
                            <x-admin.icon :name="$ok ? 'check' : 'x'" size="size-3.5" />
                        </span>
                        <span class="min-w-0">
                            <span class="font-medium">{{ __($t.'.checks.'.$item) }}</span>
                            @unless ($ok)
                                <span class="mt-0.5 block text-slate-500">{{ __($t.'.checks_fix.'.$item) }}</span>
                                @if ($item === 'bot_enabled')
                                    <a href="{{ route('admin.telegram.bot') }}" wire:navigate class="mt-0.5 block font-semibold text-brand-700 hover:underline" data-bot-link>{{ __($t.'.open_bot') }}</a>
                                @endif
                            @endunless
                        </span>
                    </li>
                @endforeach
                <li class="flex items-start gap-3 py-2.5" data-info="cart_enabled">
                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500"><x-admin.icon name="info" size="size-3.5" /></span>
                    <span class="min-w-0">
                        <span class="font-medium">{{ __($t.'.checks.cart_enabled') }}</span>
                        <span class="mt-0.5 block text-slate-500">{{ $info['cart_enabled'] ? __($t.'.cart_on') : __($t.'.cart_off') }}</span>
                    </span>
                </li>
            </ul>
        </x-admin.card>

        {{-- Menu buttons --}}
        <x-admin.card :title="__($t.'.sync.title')" :description="__($t.'.sync.hint')">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-slate-500">{{ __($t.'.sync.state') }}</dt>
                    <dd class="mt-0.5">
                        <x-admin.badge :color="match ($sync['state'] ?? null) { 'done' => 'brand', 'failed' => 'red', null => 'slate', default => 'amber' }">{{ __($t.'.sync.states.'.($sync['state'] ?? 'never')) }}</x-admin.badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.sync.generation') }}</dt>
                    <dd class="mt-0.5 font-mono" data-sync-generation>{{ $sync['generation'] ?? __('admin.common.empty_value') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.sync.last_run') }}</dt>
                    <dd class="mt-0.5">{{ ($sync['last_run_at'] ?? null) ? \Illuminate\Support\Carbon::parse($sync['last_run_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : __('admin.common.empty_value') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __($t.'.sync.synced') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ $sync['synced'] ?? 0 }}</dd>
                </div>
            </dl>

            <div class="mt-5 space-y-3 border-t border-slate-100 pt-4">
                <div>
                    <p class="text-sm font-medium text-slate-700">{{ __($t.'.botfather_url') }}</p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ __($t.'.botfather_hint') }}</p>
                    @if ($entryUrl !== null)
                        <x-admin.copy-block class="mt-2" :value="$entryUrl" snippet="botfather-url" plain :label="__($t.'.botfather_url')" data-entry-url />
                    @else
                        <p class="mt-2 text-sm text-danger" data-entry-url-missing>{{ __($t.'.checks_fix.app_url') }}</p>
                    @endif
                </div>
                <x-admin.button variant="secondary" icon="restore" wire:click="syncMenus" wire:confirm="{{ __($t.'.sync.confirm') }}" loading="syncMenus" data-action="sync-menus">{{ __($t.'.sync.button') }}</x-admin.button>
            </div>
        </x-admin.card>

        {{-- Catalog cache (plan 45 B): the filter facets, warmed every 6 hours; the button bumps and refills synchronously --}}
        <x-admin.card :title="__($t.'.catalog_cache.title')" :description="__($t.'.catalog_cache.hint')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-700" data-catalog-warmed="{{ $catalogWarmed !== null ? '1' : '0' }}">
                    @if ($catalogWarmed !== null)
                        {{ __($t.'.catalog_cache.warmed_at', [
                            'time' => \Illuminate\Support\Carbon::parse($catalogWarmed['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                            'entries' => $catalogWarmed['entries'],
                            'ms' => $catalogWarmed['ms'],
                        ]) }}
                    @else
                        {{ __($t.'.catalog_cache.never') }}
                    @endif
                </p>
                <x-admin.button variant="secondary" icon="restore" wire:click="rebuildCatalogCache" loading="rebuildCatalogCache" data-action="rebuild-catalog-cache">{{ __($t.'.catalog_cache.button') }}</x-admin.button>
            </div>
        </x-admin.card>

        {{-- Audit --}}
        <x-admin.card :title="__($t.'.audit_title')">
            @if ($audits->isEmpty())
                <p class="text-sm text-slate-500">{{ __($t.'.audit_empty') }}</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($audits as $audit)
                        <li wire:key="mini-app-audit-{{ $audit->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2" data-audit-row>
                            <x-admin.badge>{{ $audit->action->label() }}</x-admin.badge>
                            <span class="text-xs text-slate-500">{{ $audit->user?->name ?? __('admin.telegram_bot.console') }} · {{ $audit->created_at->format('d.m.Y H:i') }} · <span class="font-mono">{{ $audit->ip ?? __('admin.common.empty_value') }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>
    </div>
</div>
