@props(['diagram', 'context' => 'card'])

{{--
    Generated roll diagram (App\Support\Catalog\RollDiagram, plan 38): fallback image of a
    ribbon or a receipt paper roll without an uploaded image. Only {{ }} output of
    server-computed numbers, lang strings and the normalized "#RRGGBB" colour (as a fill
    attribute only, never style / class / url()); no scripts, hrefs or images (RD-8).
    Classes come from closed maps in RollDiagram, the viewBox variant from RollDiagram::VARIANTS.
--}}
@php
    $d = $diagram->withVariant(is_string($context) ? $context : 'card');
    $titleId = 'roll-diagram-'.$d->productId.'-'.$d->variant.'-'.\Illuminate\Support\Str::random(6);
    $base = \App\Support\Catalog\RollDiagram::BASE_SIZE;
@endphp
<svg {{ $attributes }} viewBox="0 0 {{ $d->viewSize }} {{ $d->viewSize }}" role="img" aria-labelledby="{{ $titleId }}" focusable="false" data-roll-diagram data-kind="{{ $d->kind }}" data-variant="{{ $d->variant }}">
    <title id="{{ $titleId }}">{{ $d->title }}</title>

    <g transform="scale({{ $d->unit() }})">
        {{-- Strip running off the bottom of the roll --}}
        <path d="{{ $d->stripPath }}" @if ($d->bodyHex !== null) fill="{{ $d->bodyHex }}" @endif class="{{ $d->bodyHex === null ? $d->bodyClass : '' }} {{ $d->outlineClass }}" stroke-width="0.8" stroke-linejoin="round"></path>
        @if ($d->stripLinesPath !== '')
            <path d="{{ $d->stripLinesPath }}" fill="none" class="stroke-ink-soft" stroke-width="1.6" stroke-linecap="round"></path>
        @endif

        {{-- Body (outer surface) with two hatch lines --}}
        <path d="{{ $d->bodyPath }}" @if ($d->bodyHex !== null) fill="{{ $d->bodyHex }}" @endif class="{{ $d->bodyHex === null ? $d->bodyClass : '' }} {{ $d->outlineClass }}" stroke-width="0.8"></path>
        <path d="{{ $d->hatchPath }}" fill="none" class="{{ $d->hatchClass }}" stroke-width="0.8" stroke-opacity="0.45" stroke-linecap="round"></path>

        {{-- End face: wound layers, ink band, core or coreless spiral --}}
        <ellipse cx="{{ $d->faceX }}" cy="{{ $d->faceY }}" rx="{{ $d->faceRx }}" ry="{{ $d->faceRy }}" class="{{ $d->faceClass }} stroke-ink-2" stroke-width="0.8"></ellipse>
        <path d="{{ $d->ringsPath }}" fill="none" class="stroke-ink-3" stroke-width="0.5"></path>
        @if ($d->bandHex !== null && $d->bandPath !== '')
            <path d="{{ $d->bandPath }}" fill="{{ $d->bandHex }}" fill-rule="evenodd" class="stroke-ink-2" stroke-width="0.4"></path>
        @endif
        @if ($d->coreRx !== null)
            <ellipse cx="{{ $d->faceX }}" cy="{{ $d->faceY }}" rx="{{ $d->coreRx }}" ry="{{ $d->coreRy }}" class="fill-label-liner-edge stroke-ink-2" stroke-width="0.6"></ellipse>
            <ellipse cx="{{ $d->faceX }}" cy="{{ $d->faceY }}" rx="{{ $d->holeRx }}" ry="{{ $d->holeRy }}" class="fill-bg-soft stroke-ink-2" stroke-width="0.5"></ellipse>
        @endif
        @if ($d->spiralPath !== '')
            <path d="{{ $d->spiralPath }}" fill="none" class="stroke-ink-2" stroke-width="0.6" stroke-linecap="round"></path>
        @endif

        {{-- Length on the body --}}
        <text x="{{ $d->lengthTextX }}" y="{{ $d->lengthTextY }}" text-anchor="middle" font-size="9" font-weight="600" class="{{ $d->textClass }}">{{ $d->lengthText }}</text>

        {{-- Core diameter leader (ribbons) --}}
        @if ($d->coreText !== null && $d->leaderPath !== '')
            <path d="{{ $d->leaderPath }}" fill="none" class="stroke-ink-2" stroke-width="0.5"></path>
            <text x="{{ $d->coreTextX }}" y="{{ $d->coreTextY }}" font-size="8.5" font-weight="500" class="fill-ink">{{ $d->coreText }}</text>
        @endif

        {{-- Ribbon type tag --}}
        @if ($d->tag !== null)
            <rect x="{{ $d->tagX }}" y="{{ $d->tagY }}" width="{{ $d->tagW }}" height="14" rx="3" ry="3" class="fill-bg-tint stroke-brand" stroke-width="0.6"></rect>
            <text x="{{ $d->tagX + $d->tagW / 2 }}" y="{{ $d->tagY + 10 }}" text-anchor="middle" font-size="8.5" font-weight="600" class="fill-brand-700">{{ $d->tag }}</text>
        @endif

        {{-- Width dimension of the strip --}}
        <path d="{{ $d->extensionPath }}" fill="none" class="stroke-ink-2" stroke-width="0.5"></path>
        <path d="{{ $d->dimensionLinePath }}" fill="none" class="stroke-ink-2" stroke-width="0.7"></path>
        <path d="{{ $d->arrowPath }}" class="fill-ink-2"></path>
        <text x="{{ $d->widthTextX }}" y="{{ $d->widthTextY }}" text-anchor="middle" font-size="9" font-weight="500" class="fill-ink">{{ $d->widthText }}</text>

        <text x="{{ $base / 2 }}" y="{{ $d->sizeTextY }}" text-anchor="middle" font-size="13" font-weight="600" class="fill-ink">{{ $d->size }}</text>
        @if ($d->caption !== null)
            <text x="{{ $base / 2 }}" y="{{ $d->captionY }}" text-anchor="middle" font-size="9.5" class="fill-ink-2">{{ $d->caption }}</text>
        @endif
    </g>
</svg>
