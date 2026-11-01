@props(['diagram', 'context' => 'card'])

{{--
    Generated label diagram (App\Support\Catalog\LabelDiagram): fallback image of a label
    product without an uploaded image. Only {{ }} output of server-computed numbers and
    lang strings; no scripts, hrefs or images. The viewBox variant comes from the closed
    LabelDiagram::VARIANTS map (unknown context means "card"); size classes come from the caller.
--}}
@php
    $d = $diagram->withVariant(is_string($context) ? $context : 'card');
    $suffix = $d->productId.'-'.$d->variant.'-'.\Illuminate\Support\Str::random(6);
    $titleId = 'label-diagram-'.$suffix;
    $clipId = 'label-diagram-clip-'.$suffix;
    $sizeText = __('catalog.label_diagram.title', ['size' => $d->size]);
    $mm = __('catalog.label_diagram.mm');
    $dimWidth = \App\Support\Catalog\LabelDiagram::format($d->width).' '.$mm;
    $dimHeight = \App\Support\Catalog\LabelDiagram::format($d->height).' '.$mm;
@endphp
<svg {{ $attributes }} viewBox="0 0 {{ $d->viewSize }} {{ $d->viewSize }}" role="img" aria-labelledby="{{ $titleId }}" focusable="false" data-label-diagram data-variant="{{ $d->variant }}">
    <title id="{{ $titleId }}">{{ $sizeText }}</title>
    <defs>
        <clipPath id="{{ $clipId }}"><rect x="0" y="0" width="{{ \App\Support\Catalog\LabelDiagram::BASE_SIZE }}" height="{{ \App\Support\Catalog\LabelDiagram::DRAW_H }}"></rect></clipPath>
    </defs>

    <g transform="scale({{ $d->unit() }})">
        {{-- Straight liner strip running off the top and bottom edges, with the die-cut labels cut by the frame --}}
        <g clip-path="url(#{{ $clipId }})">
            <rect x="{{ $d->linerX }}" y="-2" width="{{ $d->linerW }}" height="{{ \App\Support\Catalog\LabelDiagram::DRAW_H + 4 }}" class="fill-label-liner stroke-label-liner-edge" stroke-width="0.8"></rect>
            @foreach ($d->labels as $label)
                <rect x="{{ $label['x'] }}" y="{{ $label['y'] }}" width="{{ $d->labelW }}" height="{{ $d->labelH }}" rx="{{ $d->cornerRadius }}" ry="{{ $d->cornerRadius }}" class="fill-white stroke-ink-soft" stroke-width="0.8"></rect>
            @endforeach
        </g>

        {{-- Technical dimensions of the middle label: extension lines, dimension lines, arrowheads, values --}}
        <path d="{{ $d->extensionPath }}" fill="none" class="stroke-ink-2" stroke-width="0.5"></path>
        <path d="{{ $d->dimensionLinePath }}" fill="none" class="stroke-ink-2" stroke-width="0.7"></path>
        <path d="{{ $d->arrowPath }}" class="fill-ink-2"></path>
        <text x="{{ $d->widthTextX }}" y="{{ $d->widthTextY }}" text-anchor="middle" font-size="9" font-weight="500" class="fill-ink">{{ $dimWidth }}</text>
        <text x="{{ $d->heightTextX }}" y="{{ $d->heightTextY }}" text-anchor="middle" font-size="9" font-weight="500" transform="rotate(-90 {{ $d->heightTextX }} {{ $d->heightTextY }})" class="fill-ink">{{ $dimHeight }}</text>

        <text x="{{ \App\Support\Catalog\LabelDiagram::BASE_SIZE / 2 }}" y="{{ $d->sizeTextY }}" text-anchor="middle" font-size="13" font-weight="600" class="fill-ink">{{ $d->size }} {{ $mm }}</text>
        @if ($d->caption !== null)
            <text x="{{ \App\Support\Catalog\LabelDiagram::BASE_SIZE / 2 }}" y="{{ $d->captionY }}" text-anchor="middle" font-size="9.5" class="fill-ink-2">{{ $d->caption }}</text>
        @endif
    </g>
</svg>
