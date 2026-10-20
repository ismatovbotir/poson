@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'loading' => null,
])

{{--
    Button or link styled as a button (admin kit).
    variant: primary (brand-600) · secondary · soft · ghost · danger
    size: sm (h-9) · md (h-11 on phones, h-10 from lg) · lg (h-12)
    loading: Livewire action name; shows a spinner and disables while it runs.
    Icon-only buttons need an aria-label.
--}}
@php
    $classes = [
        'inline-flex shrink-0 items-center justify-center gap-2 rounded-lg text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50',
        match ($size) {
            'sm' => 'h-9 px-3',
            'lg' => 'h-12 px-5',
            default => 'h-11 px-4 lg:h-10',
        },
        match ($variant) {
            'secondary' => 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
            'soft' => 'bg-brand-50 text-brand-700 hover:bg-brand-100',
            'ghost' => 'text-slate-600 hover:bg-slate-100',
            'danger' => 'bg-danger text-white hover:bg-red-700',
            default => 'bg-brand-600 text-white shadow-sm hover:bg-brand-700',
        },
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-admin.icon :name="$icon" size="size-4" />@endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
        {{ $attributes->class($classes) }}
    >
        @if ($loading)
            <svg wire:loading wire:target="{{ $loading }}" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
        @endif
        @if ($icon && $loading)
            <x-admin.icon :name="$icon" size="size-4" wire:loading.remove wire:target="{{ $loading }}" />
        @elseif ($icon)
            <x-admin.icon :name="$icon" size="size-4" />
        @endif
        {{ $slot }}
    </button>
@endif
