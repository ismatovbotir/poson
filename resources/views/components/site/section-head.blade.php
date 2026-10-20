@props(['title', 'id' => null, 'subtitle' => null, 'link' => null, 'linkLabel' => null])

{{-- Section heading row: h2 + optional subtitle on the left, optional "more" link on the right. --}}
<div {{ $attributes->class('mb-7 flex flex-wrap items-end justify-between gap-4') }}>
    <div>
        <h2 @if ($id) id="{{ $id }}" @endif class="text-[25px] leading-tight font-bold tracking-[-0.02em] md:text-3xl">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1.5 text-ink-2">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($link && $linkLabel)
        <a href="{{ $link }}" class="font-semibold whitespace-nowrap text-brand-600 hover:underline">{{ $linkLabel }} <span aria-hidden="true">→</span></a>
    @endif
</div>
