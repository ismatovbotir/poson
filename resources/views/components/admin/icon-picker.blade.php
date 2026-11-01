@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'options' => [],
    'placeholder' => null,
    'hint' => null,
])

{{--
    Visual picker for a sprite icon, bound with wire:model (entangled). options: [key => label];
    each key must have a symbol #i-{key} on the page (see x-catalog.category-icon-sprite).
    placeholder is the label of the "no icon" choice (value null).
--}}
@php
    $name ??= $attributes->wire('model')->value();
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', (string) $name);
    $hasError = $name && $errors->has($name);
    $placeholder ??= __('admin.icon_picker.none');
@endphp

<div
    {{ $attributes->only('class')->class(['relative block']) }}
    x-data="{
        value: $wire.$entangle('{{ $name }}'),
        open: false,
        search: '',
        labels: @js($options),
        none: @js($placeholder),
        get currentLabel() { return this.value ? (this.labels[this.value] ?? this.value) : this.none },
        matches(label) { return label.toLowerCase().includes(this.search.trim().toLowerCase()) },
        toggle() { this.open = ! this.open; if (this.open) { this.search = ''; this.$nextTick(() => this.$refs.search.focus()) } },
        close() { if (this.open) { this.open = false; this.$refs.trigger.focus() } },
        choose(key) { this.value = key; this.open = false; this.$refs.trigger.focus() },
    }"
    x-on:keydown.escape.prevent="close()"
    x-on:click.outside="open = false"
>
    @if ($label)
        <span id="{{ $id }}-label" class="block text-sm font-semibold">{{ $label }}</span>
    @endif

    <button
        type="button"
        id="{{ $id }}"
        x-ref="trigger"
        x-on:click="toggle()"
        aria-haspopup="dialog"
        x-bind:aria-expanded="open.toString()"
        aria-controls="{{ $id }}-panel"
        @if ($label) aria-labelledby="{{ $id }}-label {{ $id }}-current" @endif
        @class([
            'flex w-full items-center gap-3 rounded-lg border bg-white p-2 text-left outline-none focus:ring-4',
            'mt-1.5' => $label,
            'border-slate-200 focus:border-brand-500 focus:ring-brand-500/15' => ! $hasError,
            'border-red-300 bg-red-50/40 focus:border-danger focus:ring-red-500/15' => $hasError,
        ])
    >
        <span class="grid size-12 shrink-0 place-items-center rounded-lg bg-slate-50 text-slate-700" aria-hidden="true">
            <template x-if="value"><svg class="ico size-10" aria-hidden="true" focusable="false"><use x-bind:href="'#i-' + value" /></svg></template>
            <span x-show="! value" class="text-xl text-slate-300">—</span>
        </span>
        <span id="{{ $id }}-current" class="min-w-0 flex-1 truncate text-sm" x-bind:class="value ? 'font-medium text-slate-900' : 'text-slate-500'" x-text="currentLabel"></span>
        <x-admin.icon name="chevron-down" size="size-4" class="shrink-0 text-slate-400" />
    </button>

    <div
        id="{{ $id }}-panel"
        x-cloak
        x-show="open"
        x-transition.origin.top
        role="dialog"
        aria-label="{{ $label ?? __('admin.icon_picker.choose') }}"
        class="absolute left-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] max-w-full rounded-xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10"
    >
        <input
            type="search"
            x-ref="search"
            x-model="search"
            placeholder="{{ __('admin.icon_picker.search') }}"
            aria-label="{{ __('admin.icon_picker.search') }}"
            class="mb-3 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm"
            x-on:keydown.enter.prevent
        >

        <div class="grid max-h-72 grid-cols-4 gap-1.5 overflow-y-auto sm:grid-cols-5">
            <button
                type="button"
                x-bind:aria-pressed="(! value).toString()"
                x-on:click="choose('')"
                title="{{ $placeholder }}"
                aria-label="{{ $placeholder }}"
                class="grid aspect-square place-items-center rounded-lg border text-slate-400 hover:bg-slate-50 focus-visible:outline-2"
                x-bind:class="! value ? 'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-500/20' : 'border-slate-200'"
            ><span class="text-xl leading-none" aria-hidden="true">—</span></button>

            @foreach ($options as $key => $optionLabel)
                <button
                    type="button"
                    wire:key="icon-{{ $key }}"
                    x-show="matches(@js($optionLabel))"
                    x-bind:aria-pressed="(value === @js($key)).toString()"
                    x-on:click="choose(@js($key))"
                    title="{{ $optionLabel }}"
                    aria-label="{{ $optionLabel }}"
                    class="grid aspect-square place-items-center rounded-lg border text-slate-700 hover:bg-slate-50 focus-visible:outline-2"
                    x-bind:class="value === @js($key) ? 'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-500/20' : 'border-slate-200'"
                ><svg class="ico size-8" aria-hidden="true" focusable="false"><use href="#i-{{ $key }}" /></svg></button>
            @endforeach
        </div>
    </div>

    @if ($hint)
        <span class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
