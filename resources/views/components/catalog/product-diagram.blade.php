@props(['diagram', 'context' => 'card'])

{{--
    Generated product diagram (App\Support\Catalog\ProductDiagram, plan 38): delegates to
    the label or the roll diagram by type; anything else renders nothing.
--}}
@if ($diagram instanceof \App\Support\Catalog\LabelDiagram)
    <x-catalog.label-diagram :diagram="$diagram" :context="$context" {{ $attributes }} />
@elseif ($diagram instanceof \App\Support\Catalog\RollDiagram)
    <x-catalog.roll-diagram :diagram="$diagram" :context="$context" {{ $attributes }} />
@endif
