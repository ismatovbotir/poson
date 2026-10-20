@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'type' => 'text',
    'hint' => null,
    'required' => false,
    'icon' => null,
])

{{--
    Text input with label, hint and validation message.
    The error key is "name", else the wire:model property (e.g. form.name.uz).
--}}
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

    <div @class(['relative', 'mt-1.5' => $label])>
        @if ($icon)
            <x-admin.icon :name="$icon" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        @endif
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($name && ! $attributes->wire('model')->value()) name="{{ $name }}" @endif
            @if ($required) required aria-required="true" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class')->class([
                'h-11 w-full rounded-lg border px-3 text-base outline-none placeholder:text-slate-400 focus:ring-4 sm:text-sm',
                'pl-10' => $icon,
                'border-slate-200 bg-white focus:border-brand-500 focus:ring-brand-500/15' => ! $hasError,
                'border-red-300 bg-red-50/40 focus:border-danger focus:ring-red-500/15' => $hasError,
            ]) }}
        >
    </div>

    @if ($hint)
        <span id="{{ $id }}-hint" class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
