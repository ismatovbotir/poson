@props(['name', 'size' => 'size-5'])

{{-- Icon from <x-site.icon-sprite />. Decorative: give the parent control an accessible name. --}}
<svg {{ $attributes->class([$size, 'shrink-0 fill-none stroke-current']) }} stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#i-{{ $name }}" /></svg>
