@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'hint' => null,
    'required' => false,
])

{{--
    Money in whole soʻm (digits only, no tiyin). The Form object converts
    soʻm ↔ tiyin (× 100); never convert in the view.
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

    <div @class([
        'flex h-11 overflow-hidden rounded-lg border focus-within:ring-4',
        'mt-1.5' => $label,
        'border-slate-200 bg-white focus-within:border-brand-500 focus-within:ring-brand-500/15' => ! $hasError,
        'border-red-300 bg-red-50/40 focus-within:border-danger focus-within:ring-red-500/15' => $hasError,
    ])>
        <input
            id="{{ $id }}"
            type="text"
            inputmode="numeric"
            pattern="[0-9 ]*"
            autocomplete="off"
            @if ($required) required aria-required="true" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class')->class(['min-w-0 flex-1 bg-transparent px-3 text-base tabular-nums outline-none sm:text-sm']) }}
        >
        <span class="flex items-center bg-slate-50 px-3 text-sm text-slate-500">{{ __('admin.money.currency') }}</span>
    </div>

    @if ($hint)
        <span id="{{ $id }}-hint" class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
