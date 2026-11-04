@php
    $t = 'telegram_status';
    $settingsRoute = \Illuminate\Support\Facades\Route::has('admin.telegram.settings') ? 'admin.telegram.settings' : (\Illuminate\Support\Facades\Route::has('admin.settings.integrations') ? 'admin.settings.integrations' : null);
    $links = [
        'bot' => ['route' => 'admin.telegram.bot', 'can' => 'telegram.bot'],
        'settings' => ['route' => $settingsRoute, 'can' => 'settings.integrations'],
        'monitoring' => ['route' => \Illuminate\Support\Facades\Route::has('admin.monitoring') ? 'admin.monitoring' : null, 'can' => 'monitoring.view'],
    ];
    $dash = __('admin.common.empty_value');
    $checkedAt = $result['checked_at'] ? \Illuminate\Support\Carbon::parse($result['checked_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : null;
@endphp

{{-- Telegram → Status (plan 21 E2). Read-only; the 60 s poll reads cached database aggregates and never calls Telegram. --}}
<div class="pb-10" wire:poll.60s.visible data-telegram-status>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.telegram') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="restore" wire:click="checkNow" loading="checkNow" data-action="check-now">{{ __($t.'.check_now') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 lg:space-y-6">
        <p class="text-sm text-slate-500" data-checked-at>{{ $checkedAt ? __($t.'.checked_at', ['time' => $checkedAt]) : __($t.'.never_checked') }}</p>

        <x-admin.card :title="__($t.'.rows_title')">
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($result['rows'] as $row)
                    @php
                        $params = array_map(fn ($value) => $value ?? $dash, $row['params']);
                        if (isset($params['chat']) && $params['chat'] !== $dash) {
                            $params['chat'] = __($t.'.chat_keys.'.$params['chat']);
                        }
                        $key = $t.'.rows.'.$row['id'].'.'.$row['state'];
                        $link = $links[$row['link']] ?? null;
                        $color = ['ok' => 'brand', 'problem' => 'red', 'unknown' => 'amber', 'neutral' => 'muted'][$row['state']];
                    @endphp
                    <li wire:key="row-{{ $row['id'] }}" class="flex flex-wrap items-start justify-between gap-3 py-3" data-row="{{ $row['id'] }}" data-state="{{ $row['state'] }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ __($t.'.rows.'.$row['id'].'.label') }}</p>
                            @if (\Illuminate\Support\Facades\Lang::has($key))
                                <p class="mt-0.5 text-slate-600">{{ __($key, $params) }}</p>
                            @endif
                            @if ($row['items'] !== [])
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 font-mono text-xs text-slate-600" data-invalid-env>
                                    @foreach ($row['items'] as $item)
                                        <li wire:key="env-{{ $item['env'] }}">{{ $item['env'] }} — {{ __($t.'.invalid_reasons.'.$item['reason']) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($row['next'])
                                <p class="mt-1.5 text-slate-700" data-next><span class="font-medium">{{ __($t.'.next_label') }}:</span> {{ __($row['next']) }}</p>
                            @endif
                            @if ($link && $link['route'])
                                @can($link['can'])
                                    @if ($row['state'] !== 'ok' && $row['state'] !== 'neutral' || $row['link'] === 'bot')
                                        <a href="{{ route($link['route']) }}" wire:navigate class="mt-1.5 inline-block text-brand-700 hover:underline" data-link="{{ $row['link'] }}">{{ __($t.'.links.'.$row['link']) }}</a>
                                    @endif
                                @endcan
                            @endif
                        </div>
                        <x-admin.badge :color="$color" dot>{{ __($t.'.states.'.$row['state']) }}</x-admin.badge>
                    </li>
                @endforeach
            </ul>
        </x-admin.card>

        <x-admin.card :title="__($t.'.counts_title')">
            @php
                $counts = $result['counts'];
            @endphp
            <dl class="grid gap-4 text-sm sm:grid-cols-3" data-counts>
                @foreach (['registered', 'registering', 'today', 'stuck', 'failed_forwards', 'unread'] as $name)
                    <div wire:key="count-{{ $name }}">
                        <dt class="text-slate-500">{{ __($t.'.counts.'.$name) }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold" data-count="{{ $name }}">{{ $counts[$name] }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-slate-500">{{ __($t.'.counts.last_message') }}</dt>
                    <dd class="mt-0.5 font-medium" data-count="last_message">{{ $counts['last_at'] ? \Illuminate\Support\Carbon::parse($counts['last_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : __($t.'.counts.none') }}</dd>
                </div>
            </dl>
        </x-admin.card>
    </div>
</div>
