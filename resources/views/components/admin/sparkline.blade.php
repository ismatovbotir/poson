@props(['points' => [], 'label' => null])

{{--
    Inline SVG sparkline (plan 37): a polyline of the given numbers, no library.
    Fewer than two points show a dash.
--}}
@php
    $points = array_values(array_map('intval', $points));
    $count = count($points);
    $min = $count ? min($points) : 0;
    $span = max(1, ($count ? max($points) : 0) - $min);
    $coords = [];

    foreach ($points as $index => $value) {
        $coords[] = round($count > 1 ? $index / ($count - 1) * 100 : 0, 1).','.round(22 - ($value - $min) / $span * 20, 1);
    }
@endphp

@if ($count > 1)
    <svg {{ $attributes->except('class')->merge(['class' => 'text-brand-600 '.($attributes->get('class') ?: 'h-6 w-24')]) }} viewBox="0 0 100 24" preserveAspectRatio="none" role="img" @if ($label) aria-label="{{ $label }}" @endif>
        <polyline points="{{ implode(' ', $coords) }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    </svg>
@else
    <span class="text-slate-300" aria-hidden="true">&mdash;</span>
@endif
