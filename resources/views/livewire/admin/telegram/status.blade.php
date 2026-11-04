@php
    $t = 'telegram_status';
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
                            $params['chat'] = in_array($row['params']['chat'], ['inbox', 'sales'], true) ? __($t.'.chat_keys.'.$row['params']['chat']) : $dash;
                        }
                        $key = $t.'.rows.'.$row['id'].'.'.$row['text'];
                        $link = $links[$row['link']] ?? null;
                        $color = ['ok' => 'brand', 'problem' => 'red', 'unknown' => 'amber', 'neutral' => 'muted'][$row['state']];
                    @endphp
                    <li wire:key="row-{{ $row['id'] }}" class="flex flex-wrap items-start justify-between gap-3 py-3" data-row="{{ $row['id'] }}" data-state="{{ $row['state'] }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ __($t.'.rows.'.$row['id'].'.label') }}</p>
                            @if (\Illuminate\Support\Facades\Lang::has($key))
                                <p class="mt-0.5 text-slate-600">{{ __($key, $params) }}</p>
                            @endif
                            @if (is_int($row['params']['pending'] ?? null) && $row['id'] === 'webhook')
                                <p class="mt-0.5 text-slate-500" data-pending>{{ __($t.'.rows.webhook.pending', ['pending' => $row['params']['pending']]) }}</p>
                            @endif
                            @if ($row['items'] !== [])
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 font-mono text-xs text-slate-600" data-invalid-env>
                                    @foreach ($row['items'] as $item)
                                        <li wire:key="env-{{ $item['env'] }}">{{ $item['env'] }} — {{ __($t.'.invalid_reasons.'.(in_array($item['reason'], ['format', 'ignored', 'unknown'], true) ? $item['reason'] : 'unknown')) }}</li>
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

        @if ($result['rows'] === [])
            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert" data-unavailable>{{ __($t.'.unavailable') }}</p>
        @endif

        @if ($result['counts'] !== null)
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
        @endif

        {{-- Staff inbox actions (T21-35): key, action, who, when. Never the IP or any content. --}}
        <section aria-labelledby="inbox-audit-title" data-inbox-audit>
            <h2 id="inbox-audit-title" class="text-lg font-bold tracking-tight">{{ __($t.'.inbox_audit_title') }}</h2>
            <p class="mt-0.5 text-sm text-slate-500">{{ __($t.'.inbox_audit_subtitle') }}</p>
            @if ($inboxAudits->isEmpty())
                <p class="mt-4 text-sm text-slate-500" data-inbox-audit-empty>{{ __($t.'.inbox_audit_empty') }}</p>
            @else
                <x-admin.table class="mt-4">
                    <x-slot:caption>{{ __($t.'.inbox_audit_title') }}</x-slot:caption>
                    <x-slot:head>
                        <th class="px-4 py-3">{{ __('admin.settings.integrations.audit.when') }}</th>
                        <th class="px-4 py-3">{{ __('admin.settings.integrations.audit.user') }}</th>
                        <th class="px-4 py-3">{{ __('admin.settings.integrations.audit.key') }}</th>
                        <th class="px-4 py-3">{{ __('admin.settings.integrations.audit.action') }}</th>
                    </x-slot:head>
                    @foreach ($inboxAudits as $audit)
                        <tr wire:key="inbox-audit-{{ $audit->id }}" data-inbox-audit-row>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $audit->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $audit->user?->name ?? $dash }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $audit->key }}</td>
                            <td class="px-4 py-3"><x-admin.badge color="slate">{{ $audit->action->label() }}</x-admin.badge></td>
                        </tr>
                    @endforeach
                </x-admin.table>

                <x-admin.mobile-list class="mt-4">
                    @foreach ($inboxAudits as $audit)
                        <article wire:key="inbox-audit-m-{{ $audit->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs">{{ $audit->key }}</span>
                                <x-admin.badge color="slate">{{ $audit->action->label() }}</x-admin.badge>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ $audit->user?->name ?? $dash }} · {{ $audit->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>
                        </article>
                    @endforeach
                </x-admin.mobile-list>

                {{ $inboxAudits->links() }}
            @endif
        </section>
    </div>
</div>
