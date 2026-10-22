@php
    use App\Enums\DeviceType;
    use App\Support\NumberFormat;

    $percent = fn (?float $value): string => $value === null ? '—' : NumberFormat::format($value).'%';
    $deviceLabels = collect(DeviceType::cases())->mapWithKeys(fn (DeviceType $device) => [$device->value => $device->label()])->all();
    $localeLabels = collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale) => [$locale => __('analytics.locales.'.$locale)])->all();
    $kpis = [
        ['key' => 'visitors', 'icon' => 'users', 'value' => NumberFormat::format($totals['visitors']), 'tone' => 'bg-brand-50 text-brand-600'],
        ['key' => 'page_views', 'icon' => 'eye', 'value' => NumberFormat::format($totals['page_views']), 'tone' => 'bg-sky-50 text-sky-600'],
        ['key' => 'leads', 'icon' => 'inbox', 'value' => NumberFormat::format($totals['leads']), 'tone' => 'bg-violet-50 text-violet-600'],
        ['key' => 'conversion', 'icon' => 'chart', 'value' => $percent($totals['conversion']), 'tone' => 'bg-amber-50 text-warn'],
    ];
@endphp

<div>
    <x-admin.page-header :title="__('analytics.traffic.title')" :subtitle="__('analytics.traffic.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.dashboard') }} <span class="text-slate-300">/</span> {{ __('analytics.menu.traffic') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            @include('livewire.admin.analytics.partials.range-tabs')
        </x-slot:actions>
    </x-admin.page-header>

    @if ($ga4Url || $metrikaUrl)
        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm" data-external-analytics>
            <span class="text-slate-500">{{ __('analytics.traffic.external') }}</span>
            @if ($ga4Url)
                <x-admin.button variant="secondary" size="sm" :href="$ga4Url" target="_blank" rel="noopener noreferrer" icon="external">{{ __('analytics.traffic.open_ga4') }}</x-admin.button>
            @endif
            @if ($metrikaUrl)
                <x-admin.button variant="secondary" size="sm" :href="$metrikaUrl" target="_blank" rel="noopener noreferrer" icon="external">{{ __('analytics.traffic.open_metrika') }}</x-admin.button>
            @endif
        </div>
    @endif

    <div class="relative">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-canvas/50" aria-hidden="true"></div>

        <section class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-label="{{ __('analytics.traffic.kpis') }}">
            @foreach ($kpis as $kpi)
                <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" data-kpi="{{ $kpi['key'] }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">{{ __('analytics.traffic.kpi.'.$kpi['key']) }}</p>
                        <span class="grid size-8 place-items-center rounded-lg {{ $kpi['tone'] }}"><x-admin.icon :name="$kpi['icon']" size="size-4" /></span>
                    </div>
                    <p class="mt-3 text-xl font-bold tracking-tight tabular-nums sm:text-[1.75rem]">{{ $kpi['value'] }}</p>
                </div>
            @endforeach
        </section>

        @if ($totals['visitors'] === 0)
            <p class="mt-4 flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800" data-empty>
                <x-admin.icon name="info" size="size-5" class="shrink-0" />{{ __('analytics.traffic.empty') }}
            </p>
        @endif

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 xl:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold">{{ __('analytics.traffic.chart_title') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('analytics.common.last_days', ['count' => $days]) }}</p>
                    </div>
                    <p class="text-right text-sm"><span class="block text-lg font-bold tabular-nums">{{ NumberFormat::format($totals['visitors']) }}</span><span class="text-slate-400">{{ __('analytics.traffic.kpi.visitors') }}</span></p>
                </div>
                @include('livewire.admin.analytics.partials.bar-chart', ['series' => $series])
            </section>

            <x-admin.card :title="__('analytics.traffic.channels')" :padding="false">
                @if ($channels === [])
                    <p class="px-4 py-6 text-sm text-slate-500 sm:px-6">{{ __('analytics.common.no_data') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="py-2.5 pl-4 pr-3 sm:pl-6">{{ __('analytics.common.channel') }}</th>
                                    <th class="px-3 py-2.5 text-right">{{ __('analytics.common.visitors') }}</th>
                                    <th class="px-3 py-2.5 text-right">{{ __('analytics.common.leads') }}</th>
                                    <th class="py-2.5 pl-3 pr-4 text-right sm:pr-6">{{ __('analytics.common.conversion') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($channels as $row)
                                    <tr wire:key="channel-{{ $row['channel']->value }}" data-channel="{{ $row['channel']->value }}">
                                        <td class="py-2.5 pl-4 pr-3 sm:pl-6"><x-admin.badge :color="$row['channel']->color()">{{ $row['channel']->label() }}</x-admin.badge></td>
                                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ NumberFormat::format($row['visitors']) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ NumberFormat::format($row['leads']) }}</td>
                                        <td class="py-2.5 pl-3 pr-4 text-right tabular-nums text-slate-500 sm:pr-6">{{ $percent($row['conversion']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-admin.card :title="__('analytics.traffic.sources')" :description="__('analytics.traffic.sources_hint')" :padding="false">
                @if ($sources === [])
                    <p class="px-4 py-6 text-sm text-slate-500 sm:px-6">{{ __('analytics.common.no_data') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="py-2.5 pl-4 pr-3 sm:pl-6">{{ __('analytics.common.source') }}</th>
                                    <th class="px-3 py-2.5 text-right">{{ __('analytics.common.visitors') }}</th>
                                    <th class="py-2.5 pl-3 pr-4 text-right sm:pr-6">{{ __('analytics.common.leads') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($sources as $row)
                                    <tr wire:key="source-{{ $row['channel']->value }}-{{ md5($row['source']) }}" data-source="{{ $row['source'] }}">
                                        <td class="max-w-0 py-2.5 pl-4 pr-3 sm:pl-6">
                                            <span class="flex items-center gap-2"><x-admin.badge :color="$row['channel']->color()">{{ $row['channel']->label() }}</x-admin.badge><span class="truncate" title="{{ $row['source'] }}">{{ $row['source'] }}</span></span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ NumberFormat::format($row['visitors']) }}</td>
                                        <td class="py-2.5 pl-3 pr-4 text-right tabular-nums">{{ NumberFormat::format($row['leads']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.card>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                <x-admin.card :title="__('analytics.traffic.devices')">
                    @include('livewire.admin.analytics.partials.share-list', ['rows' => $devices, 'labels' => $deviceLabels, 'key' => 'device'])
                </x-admin.card>
                <x-admin.card :title="__('analytics.traffic.locales')">
                    @include('livewire.admin.analytics.partials.share-list', ['rows' => $locales, 'labels' => $localeLabels, 'key' => 'locale'])
                </x-admin.card>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-admin.card :title="__('analytics.traffic.top_pages')" :padding="false">
                @include('livewire.admin.analytics.partials.pages-table', ['rows' => $pages, 'hitsLabel' => __('analytics.common.views')])
            </x-admin.card>
            <x-admin.card :title="__('analytics.traffic.top_products')" :padding="false">
                @include('livewire.admin.analytics.partials.products-table', ['rows' => $products, 'hitsLabel' => __('analytics.common.views_short')])
            </x-admin.card>
        </div>

        <p class="mt-4 text-xs text-slate-500">{{ __('analytics.traffic.privacy_note') }}</p>
    </div>
</div>
