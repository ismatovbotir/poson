@php
    /**
     * Server-rendered SVG bar chart (no chart library).
     * $series: list<array{date: string, visitors: int, page_views: int, leads: int}>
     */
    $values = array_column($series, 'visitors');
    $heights = \App\Support\Analytics\TrafficReport::heights($values);
    $count = max(1, count($series));
    $slot = 10;
    $label = fn (string $date): string => (string) \App\Support\DateFormat::iso(\Carbon\CarbonImmutable::parse($date), 'D MMM');
@endphp

<div class="relative mt-5 h-44 sm:h-56">
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between" aria-hidden="true">
        <div class="border-t border-dashed border-slate-200"></div>
        <div class="border-t border-dashed border-slate-200"></div>
        <div class="border-t border-dashed border-slate-200"></div>
        <div class="border-t border-slate-200"></div>
    </div>
    <svg class="relative h-full w-full" viewBox="0 0 {{ $count * $slot }} 100" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.traffic.chart_label') }}" data-chart="visitors">
        @foreach ($series as $index => $day)
            @php $height = max($day['visitors'] > 0 ? 1.5 : 0, $heights[$index]); @endphp
            <g>
                <title>{{ $label($day['date']) }}: {{ __('analytics.traffic.chart_tooltip', ['visitors' => \App\Support\NumberFormat::format($day['visitors']), 'leads' => \App\Support\NumberFormat::format($day['leads'])]) }}</title>
                <rect x="{{ $index * $slot }}" y="0" width="{{ $slot }}" height="100" fill="transparent" />
                <rect x="{{ $index * $slot + 1.5 }}" y="{{ 100 - $height }}" width="{{ $slot - 3 }}" height="{{ $height }}" rx="1"
                    @class(['fill-brand-500' => $index >= $count - 7, 'fill-brand-200' => $index < $count - 7]) />
                @if ($day['leads'] > 0)
                    <rect x="{{ $index * $slot + 1.5 }}" y="{{ max(0, 100 - $height - 4) }}" width="{{ $slot - 3 }}" height="2.5" rx="1" class="fill-slate-900" data-lead-marker />
                @endif
            </g>
        @endforeach
    </svg>
</div>
<div class="mt-2 flex justify-between text-[11px] text-slate-400" aria-hidden="true">
    @if ($series !== [])
        <span>{{ $label($series[0]['date']) }}</span>
        <span>{{ $label($series[intdiv(count($series) - 1, 2)]['date']) }}</span>
        <span>{{ $label($series[count($series) - 1]['date']) }}</span>
    @endif
</div>
<p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-500" aria-hidden="true"></span>{{ __('analytics.traffic.legend_visitors') }}</span>
    <span class="inline-flex items-center gap-1.5"><span class="h-1 w-2.5 rounded-sm bg-slate-900" aria-hidden="true"></span>{{ __('analytics.traffic.legend_leads') }}</span>
</p>
