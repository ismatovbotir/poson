@props(['name', 'size' => 'size-5'])

{{-- Icon from <x-admin.icon-sprite />. Decorative: give the parent control an accessible name. --}}
<svg {{ $attributes->class(['ico', $size]) }} aria-hidden="true" focusable="false"><use href="#i-{{ $name }}" /></svg>
