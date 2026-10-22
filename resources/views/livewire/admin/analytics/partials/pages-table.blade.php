{{-- Top pages. $rows: list<array{path, status, hits, visitors}>; $hitsLabel; $showVisitors. --}}
@if ($rows === [])
    <p class="px-4 py-6 text-sm text-slate-500 sm:px-6">{{ __('analytics.common.no_data') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="py-2.5 pl-4 pr-3 sm:pl-6">{{ __('analytics.common.page') }}</th>
                    <th class="px-3 py-2.5 text-right">{{ $hitsLabel }}</th>
                    @if ($showVisitors ?? true)
                        <th class="py-2.5 pl-3 pr-4 text-right sm:pr-6">{{ __('analytics.common.visitors') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <tr wire:key="page-{{ md5($row['path'].'|'.$row['status']) }}">
                        <td class="max-w-0 py-2.5 pl-4 pr-3 sm:pl-6">
                            <span class="flex items-center gap-2">
                                <span class="truncate font-mono text-xs" title="{{ $row['path'] }}">{{ $row['path'] }}</span>
                                @if ($row['status'] >= 400)
                                    <x-admin.badge :color="$row['status'] >= 500 ? 'red' : 'amber'">{{ $row['status'] }}</x-admin.badge>
                                @endif
                            </span>
                        </td>
                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ \App\Support\NumberFormat::format($row['hits']) }}</td>
                        @if ($showVisitors ?? true)
                            <td class="py-2.5 pl-3 pr-4 text-right tabular-nums text-slate-500 sm:pr-6">{{ \App\Support\NumberFormat::format($row['visitors']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
