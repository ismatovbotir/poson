@props(['label', 'description' => null, 'name' => null, 'id' => null])

{{-- Switch (checkbox with role="switch"). Bind with wire:model. --}}
@php
    $name ??= $attributes->wire('model')->value();
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', (string) $name);
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="flex cursor-pointer items-start gap-3 text-sm">
        <input
            id="{{ $id }}"
            type="checkbox"
            role="switch"
            @if ($description) aria-describedby="{{ $id }}-desc" @endif
            {{ $attributes->except('class')->class(['peer sr-only']) }}
        >
        <span aria-hidden="true" class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full bg-slate-200 transition after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-brand-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2"></span>
        <span class="min-w-0">
            <span class="block font-medium text-slate-900">{{ $label }}</span>
            @if ($description)
                <span id="{{ $id }}-desc" class="block text-xs text-slate-500">{{ $description }}</span>
            @endif
        </span>
    </label>

    @if ($name)
        <x-admin.field-error :name="$name" />
    @endif
</div>
