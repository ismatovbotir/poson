{{-- Visitors per value with a share bar. $rows: list<array{value, visitors, share}>; $labels: value => label. --}}
@if ($rows === [])
    <p class="text-sm text-slate-500">{{ __('analytics.common.no_data') }}</p>
@else
    <ul class="space-y-3">
        @foreach ($rows as $row)
            <li wire:key="share-{{ $key }}-{{ $row['value'] }}">
                <div class="flex justify-between gap-3 text-sm">
                    <span class="font-medium">{{ $labels[$row['value']] ?? $row['value'] }}</span>
                    <span class="tabular-nums text-slate-500"><b class="font-semibold text-slate-900">{{ \App\Support\NumberFormat::format($row['visitors']) }}</b> · {{ \App\Support\NumberFormat::format($row['share']) }}%</span>
                </div>
                <div class="mt-1.5 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-500" style="width: {{ $row['share'] }}%"></div></div>
            </li>
        @endforeach
    </ul>
@endif
