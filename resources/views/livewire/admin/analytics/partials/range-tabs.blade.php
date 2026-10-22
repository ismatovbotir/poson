{{-- 7 / 30 / 90-day switch ($ranges, $days). --}}
<div class="scrollbar-none flex overflow-x-auto rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="{{ __('analytics.common.range') }}">
    @foreach ($ranges as $range)
        <button type="button" wire:click="setDays({{ $range }})" aria-pressed="{{ $days === $range ? 'true' : 'false' }}" data-range="{{ $range }}"
            @class([
                'h-8 flex-1 whitespace-nowrap rounded-md px-3 text-sm font-semibold',
                'bg-slate-900 text-white shadow-sm' => $days === $range,
                'text-slate-600 hover:text-slate-900' => $days !== $range,
            ])>{{ __('analytics.common.days', ['count' => $range]) }}</button>
    @endforeach
</div>
