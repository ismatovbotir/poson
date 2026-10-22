@php
    use App\Support\Analytics\TrafficReport;
    use App\Support\NumberFormat;

    $percent = fn (?float $value): string => $value === null ? '—' : NumberFormat::format($value).'%';
@endphp

<div>
    <x-admin.page-header :title="__('analytics.ai.title')" :subtitle="__('analytics.ai.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.dashboard') }} <span class="text-slate-300">/</span> {{ __('analytics.menu.ai') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            @include('livewire.admin.analytics.partials.range-tabs')
        </x-slot:actions>
    </x-admin.page-header>

    <div class="relative">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-canvas/50" aria-hidden="true"></div>

        {{-- People arriving from AI assistants vs search engines vs everything else --}}
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4" aria-label="{{ __('analytics.ai.comparison') }}">
            @foreach (['ai' => 'bg-violet-50 text-violet-600', 'search' => 'bg-sky-50 text-sky-600', 'other' => 'bg-slate-100 text-slate-600'] as $group => $tone)
                <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" data-comparison="{{ $group }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-medium text-slate-500">{{ __('analytics.ai.groups.'.$group) }}</p>
                        <span class="grid size-8 place-items-center rounded-lg {{ $tone }}"><x-admin.icon :name="$group === 'ai' ? 'sparkles' : ($group === 'search' ? 'search' : 'globe')" size="size-4" /></span>
                    </div>
                    <p class="mt-3 text-xl font-bold tracking-tight tabular-nums sm:text-[1.75rem]">{{ NumberFormat::format($comparison[$group]['visitors']) }} <span class="text-sm font-semibold text-slate-400">{{ __('analytics.common.visitors_short') }}</span></p>
                    <p class="mt-1.5 text-xs font-medium text-slate-500">{{ __('analytics.ai.leads_conversion', ['leads' => NumberFormat::format($comparison[$group]['leads']), 'conversion' => $percent($comparison[$group]['conversion'])]) }}</p>
                </div>
            @endforeach
        </section>

        <x-admin.card class="mt-4" :title="__('analytics.ai.crawlers')" :description="__('analytics.ai.crawlers_hint')" :padding="false">
            @if ($bots === [])
                <p class="px-4 py-6 text-sm text-slate-500 sm:px-6" data-empty>{{ __('analytics.ai.no_bots') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-2.5 pl-4 pr-3 sm:pl-6">{{ __('analytics.ai.columns.bot') }}</th>
                                <th class="px-3 py-2.5">{{ __('analytics.ai.columns.purpose') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __('analytics.ai.columns.hits') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __('analytics.ai.columns.ips') }}</th>
                                <th class="px-3 py-2.5">{{ __('analytics.ai.columns.per_day') }}</th>
                                <th class="px-3 py-2.5">{{ __('analytics.ai.columns.verification') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __('analytics.ai.columns.errors') }}</th>
                                <th class="py-2.5 pl-3 pr-4 sm:pr-6">{{ __('analytics.ai.columns.last_seen') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($bots as $row)
                                <tr wire:key="bot-{{ $row['bot']->key }}" data-bot="{{ $row['bot']->key }}" @class(['bg-brand-50/40' => $bot === $row['bot']->key])>
                                    <td class="py-2.5 pl-4 pr-3 sm:pl-6">
                                        <button type="button" wire:click="$set('bot', '{{ $bot === $row['bot']->key ? '' : $row['bot']->key }}')" class="text-left font-semibold hover:text-brand-700" aria-pressed="{{ $bot === $row['bot']->key ? 'true' : 'false' }}">{{ $row['bot']->label() }}</button>
                                        @if ($row['bot']->vendor !== '')<p class="text-xs text-slate-500">{{ $row['bot']->vendor }}</p>@endif
                                    </td>
                                    <td class="px-3 py-2.5"><x-admin.badge :color="$row['bot']->purpose->color()">{{ $row['bot']->purpose->label() }}</x-admin.badge></td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ NumberFormat::format($row['hits']) }}</td>
                                    {{-- IPs are stored only for crawlers that can be verified. --}}
                                    <td class="px-3 py-2.5 text-right tabular-nums text-slate-500" data-ips>{{ $row['bot']->isVerifiable() ? NumberFormat::format($row['ips']) : '—' }}</td>
                                    <td class="px-3 py-2.5">
                                        <svg class="h-7 w-28 text-brand-500" viewBox="0 0 120 28" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.ai.sparkline_label', ['bot' => $row['bot']->label()]) }}" data-sparkline>
                                            <polyline points="{{ TrafficReport::sparkline($row['series']) }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        </svg>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($row['bot']->isVerifiable())
                                            <span class="flex flex-wrap gap-1">
                                                <x-admin.badge color="brand" data-verified="{{ $row['verified'] }}">{{ __('analytics.ai.verified', ['count' => NumberFormat::format($row['verified'])]) }}</x-admin.badge>
                                                @if ($row['unverified'] > 0)<x-admin.badge color="muted">{{ __('analytics.ai.unverified', ['count' => NumberFormat::format($row['unverified'])]) }}</x-admin.badge>@endif
                                                @if ($row['spoofed'] > 0)<x-admin.badge color="red" data-spoofed="{{ $row['spoofed'] }}">{{ __('analytics.ai.spoofed', ['count' => NumberFormat::format($row['spoofed'])]) }}</x-admin.badge>@endif
                                            </span>
                                        @else
                                            <x-admin.badge color="outline">{{ __('analytics.enums.bot_verification.ua_only') }}</x-admin.badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">
                                        @if ($row['errors_4xx'] + $row['errors_5xx'] === 0)
                                            <span class="text-slate-400">0</span>
                                        @else
                                            <span class="text-warn">{{ NumberFormat::format($row['errors_4xx']) }}</span> / <span class="text-danger">{{ NumberFormat::format($row['errors_5xx']) }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 pl-3 pr-4 text-slate-500 sm:pr-6">
                                        {{ \App\Support\DateFormat::iso($row['last_seen_at']?->timezone(\App\Support\Analytics\TrafficRecorder::timezone()), 'D MMM, HH:mm') ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 sm:px-6">{{ __('analytics.ai.errors_hint') }}</p>
            @endif
        </x-admin.card>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="relative">
                <span class="sr-only">{{ __('analytics.ai.filter_bot') }}</span>
                <select wire:model.live="bot" class="h-10 appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                    <option value="">{{ __('analytics.ai.all_bots') }}</option>
                    @foreach ($trackable as $definition)
                        <option value="{{ $definition->key }}">{{ $definition->label() }}</option>
                    @endforeach
                </select>
                <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
            </label>
            <p class="text-sm text-slate-500">{{ $selected ? __('analytics.ai.showing_bot', ['bot' => $selected->label()]) : __('analytics.ai.showing_all') }}</p>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-admin.card :title="__('analytics.ai.top_pages')" :padding="false">
                @include('livewire.admin.analytics.partials.pages-table', ['rows' => $pages, 'hitsLabel' => __('analytics.ai.columns.hits'), 'showVisitors' => false])
            </x-admin.card>
            <x-admin.card :title="__('analytics.ai.top_products')" :padding="false">
                @include('livewire.admin.analytics.partials.products-table', ['rows' => $products, 'hitsLabel' => __('analytics.ai.hits_short')])
            </x-admin.card>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-admin.card :title="__('analytics.ai.not_found')" :description="__('analytics.ai.not_found_hint')" :padding="false">
                <div data-errors>
                    @include('livewire.admin.analytics.partials.pages-table', ['rows' => $notFound, 'hitsLabel' => __('analytics.ai.columns.hits'), 'showVisitors' => false])
                </div>
            </x-admin.card>
            <x-admin.card :title="__('analytics.ai.ai_sources')" :description="__('analytics.ai.ai_sources_hint')" :padding="false">
                @if ($aiSources === [])
                    <p class="px-4 py-6 text-sm text-slate-500 sm:px-6">{{ __('analytics.common.no_data') }}</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($aiSources as $row)
                            <li wire:key="ai-source-{{ md5($row['source']) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm sm:px-6" data-ai-source="{{ $row['source'] }}">
                                <span class="truncate font-medium">{{ $row['source'] }}</span>
                                <span class="shrink-0 tabular-nums text-slate-500"><b class="font-semibold text-slate-900">{{ NumberFormat::format($row['visitors']) }}</b> {{ __('analytics.common.visitors_short') }} · {{ __('analytics.ai.leads_count', ['count' => NumberFormat::format($row['leads'])]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        </div>
    </div>
</div>
