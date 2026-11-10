@php
    $t = 'chatbot_admin.stats';
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
    $max = max(1, (int) ($perRule->max('total') ?? 1));
@endphp

<div data-chatbot-stats>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __($t.'.range') }}</span>
            <select wire:model.live="days" class="{{ $selectClass }}">
                @foreach (\App\Livewire\Admin\Chatbot\Stats::RANGES as $range)
                    <option value="{{ $range }}">{{ __($t.'.last_days', ['days' => $range]) }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __($t.'.channel') }}</span>
            <select wire:model.live="channel" class="{{ $selectClass }}">
                <option value="">{{ __($t.'.channel_all') }}</option>
                @foreach (\App\Enums\ChatChannel::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (\App\Enums\ChatMetric::cases() as $metric)
            <div class="rounded-2xl border border-slate-200 bg-white p-4" data-metric="{{ $metric->value }}">
                <p class="text-xs font-semibold text-slate-500">{{ $metric->label() }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ number_format((int) ($totals[$metric->value] ?? 0), 0, '.', ' ') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __($t.'.metric_help.'.$metric->value) }}</p>
            </div>
        @endforeach
        <a href="{{ route('admin.chatbot.unmatched') }}" wire:navigate class="rounded-2xl border border-amber-200 bg-amber-50 p-4 hover:bg-amber-100/60" data-waiting>
            <p class="text-xs font-semibold text-amber-800">{{ __($t.'.waiting') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-amber-900">{{ $waiting }}</p>
            <p class="mt-1 text-xs text-amber-800">{{ __($t.'.waiting_help') }}</p>
        </a>
    </div>

    @if ($channel === '' && $byChannel->isNotEmpty())
        <x-admin.card :title="__($t.'.by_channel')" class="mt-5">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="py-2 pr-4 font-semibold">{{ __($t.'.channel') }}</th>
                            @foreach (\App\Enums\ChatMetric::cases() as $metric)
                                <th class="px-3 py-2 text-right font-semibold">{{ $metric->label() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($byChannel as $channelKey => $metrics)
                            <tr>
                                <td class="py-2 pr-4 font-semibold">{{ \App\Enums\ChatChannel::from($channelKey)->label() }}</td>
                                @foreach (\App\Enums\ChatMetric::cases() as $metric)
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $metrics[$metric->value] ?? 0 }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    @endif

    <x-admin.card :title="__($t.'.per_rule')" :description="__($t.'.per_rule_help')" class="mt-5">
        @if ($perRule->isEmpty())
            <p class="text-sm text-slate-500">{{ __($t.'.no_data') }}</p>
        @else
            <ul class="space-y-2.5" data-per-rule>
                @foreach ($perRule as $row)
                    <li>
                        <div class="flex items-center justify-between gap-3 text-sm"><span class="truncate font-medium">{{ $row['name'] }}</span><span class="font-semibold tabular-nums">{{ $row['total'] }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-600" style="width: {{ round($row['total'] / $max * 100) }}%"></div></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.card>
</div>
