@props(['size' => 'size-8'])

{{-- Poson mark (public/favicon.svg). Decorative: the parent link carries the name. --}}
<img src="{{ asset('favicon.svg') }}" alt="" width="32" height="32" {{ $attributes->class([$size, 'shrink-0']) }}>
