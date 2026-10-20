@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'options' => [],
    'placeholder' => null,
    'hint' => null,
    'required' => false,
])

{{--
    Native select. options: [value => label]; or pass <option>s in the slot.
    placeholder adds an empty first option.
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
        </label>
    @endif

    <div @class(['relative', 'mt-1.5' => $label])>
        <select
            id="{{ $id }}"
            @if ($name && ! $attributes->wire('model')->value()) name="{{ $name }}" @endif
            @if ($required) required aria-required="true" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class')->class([
                'h-11 w-full appearance-none rounded-lg border bg-white pl-3 pr-9 text-base outline-none focus:ring-4 sm:text-sm',
                'border-slate-200 focus:border-brand-500 focus:ring-brand-500/15' => ! $hasError,
                'border-red-300 bg-red-50/40 focus:border-danger focus:ring-red-500/15' => $hasError,
            ]) }}
        >
            @if ($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options as $value => $optionLabel)
                <option value="{{ $value }}">{{ $optionLabel }}</option>
            @endforeach
            {{ $slot }}
        </select>
        <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
    </div>

    @if ($hint)
        <span id="{{ $id }}-hint" class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
