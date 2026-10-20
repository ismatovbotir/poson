@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'rows' => 4,
    'hint' => null,
    'required' => false,
])

{{-- Textarea with label, hint and validation message (error key = name or wire:model). --}}
@php
    $name ??= $attributes->wire('model')->value();
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', (string) $name);
    $hasError = $name && $errors->has($name);
    $describedBy = collect([$hint ? $id.'-hint' : null, $hasError ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div {{ $attributes->only('class')->class(['block']) }}>
    @if ($label)
        <label for="{{ $id }}" class="flex items-center gap-1 text-sm font-semibold">
            {{ $label }}
            @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
            {{ $labelAside ?? '' }}
        </label>
    @endif

    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($name && ! $attributes->wire('model')->value()) name="{{ $name }}" @endif
        @if ($required) required aria-required="true" @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->class([
            'w-full rounded-lg border px-3 py-2.5 text-base outline-none placeholder:text-slate-400 focus:ring-4 sm:text-sm',
            'mt-1.5' => $label,
            'border-slate-200 bg-white focus:border-brand-500 focus:ring-brand-500/15' => ! $hasError,
            'border-red-300 bg-red-50/40 focus:border-danger focus:ring-red-500/15' => $hasError,
        ]) }}
    >{{ $slot }}</textarea>

    @if ($hint)
        <span id="{{ $id }}-hint" class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
