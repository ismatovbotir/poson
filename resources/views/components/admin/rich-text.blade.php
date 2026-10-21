@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'hint' => null,
    'required' => false,
])

{{--
    Rich text editor (Tiptap 3 StarterKit, plan 03 D12): bold, italic, H2/H3,
    lists, links (https/mailto). Bind with wire:model="form.description.uz";
    the value is HTML, cleaned on save by App\Support\HtmlSanitizer.
    The editor is wire:ignore'd and entangled with the property. Slot "labelAside"
    sits next to the label (e.g. <x-admin.ai-button>).
--}}
@php
    $model = $attributes->wire('model')->value();
    $name ??= $model;
    $id ??= 'f-'.str_replace(['.', '[', ']'], '-', (string) $name);
    $hasError = $name && $errors->has($name);
    $tools = [
        ['bold', 'toggleBold', [], __('admin.rich_text.bold'), 'B', 'font-bold'],
        ['italic', 'toggleItalic', [], __('admin.rich_text.italic'), 'I', 'italic'],
        ['heading', 'toggleHeading', ['level' => 2], __('admin.rich_text.heading_2'), 'H2', 'font-semibold'],
        ['heading', 'toggleHeading', ['level' => 3], __('admin.rich_text.heading_3'), 'H3', 'font-semibold'],
    ];
@endphp

<div {{ $attributes->only('class')->class(['block']) }}>
    @if ($label)
        <span id="{{ $id }}-label" class="flex items-center gap-1 text-sm font-semibold">
            {{ $label }}
            @if ($required)<span class="text-danger" aria-hidden="true">*</span>@endif
            {{ $labelAside ?? '' }}
        </span>
    @endif

    <div @class([
        'overflow-hidden rounded-lg border bg-white focus-within:ring-4',
        'mt-1.5' => $label,
        'border-slate-200 focus-within:border-brand-500 focus-within:ring-brand-500/15' => ! $hasError,
        'border-red-300 focus-within:border-danger focus-within:ring-red-500/15' => $hasError,
    ])>
        <div
            wire:ignore
            x-data="richText($wire.entangle(@js($model)))"
            id="{{ $id }}"
            data-labelledby="{{ $label ? $id.'-label' : '' }}"
            data-link-prompt="{{ __('admin.rich_text.link_prompt') }}"
        >
            <div class="scrollbar-none flex gap-0.5 overflow-x-auto border-b border-slate-100 bg-slate-50 p-1 text-sm text-slate-600" role="toolbar" aria-label="{{ __('admin.rich_text.toolbar') }}">
                @foreach ($tools as [$mark, $command, $args, $toolLabel, $text, $style])
                    <button
                        type="button"
                        x-on:click="run(@js($command), {{ $args ? Js::from($args) : '' }})"
                        x-bind:class="isActive(@js($mark), @js((object) $args)) ? 'bg-white text-brand-700 shadow-sm' : 'hover:bg-white'"
                        x-bind:aria-pressed="isActive(@js($mark), @js((object) $args)).toString()"
                        class="h-8 min-w-8 rounded px-2 {{ $style }}"
                        title="{{ $toolLabel }}"
                        aria-label="{{ $toolLabel }}"
                    >{{ $text }}</button>
                @endforeach
                <span class="mx-1 w-px self-stretch bg-slate-200" aria-hidden="true"></span>
                <button type="button" x-on:click="run('toggleBulletList')" x-bind:class="isActive('bulletList') ? 'bg-white text-brand-700 shadow-sm' : 'hover:bg-white'" x-bind:aria-pressed="isActive('bulletList').toString()" class="grid size-8 place-items-center rounded" title="{{ __('admin.rich_text.bullet_list') }}" aria-label="{{ __('admin.rich_text.bullet_list') }}"><x-admin.icon name="list" size="size-4" /></button>
                <button type="button" x-on:click="run('toggleOrderedList')" x-bind:class="isActive('orderedList') ? 'bg-white text-brand-700 shadow-sm' : 'hover:bg-white'" x-bind:aria-pressed="isActive('orderedList').toString()" class="h-8 min-w-8 rounded px-2 text-xs font-bold" title="{{ __('admin.rich_text.ordered_list') }}" aria-label="{{ __('admin.rich_text.ordered_list') }}">1.</button>
                <button type="button" x-on:click="link()" x-bind:class="isActive('link') ? 'bg-white text-brand-700 shadow-sm' : 'hover:bg-white'" x-bind:aria-pressed="isActive('link').toString()" class="grid size-8 place-items-center rounded" title="{{ __('admin.rich_text.link') }}" aria-label="{{ __('admin.rich_text.link') }}"><x-admin.icon name="link" size="size-4" /></button>
            </div>
            <div x-ref="editor"></div>
        </div>
    </div>

    @if ($hint)
        <span id="{{ $id }}-hint" class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @if ($name)
        <x-admin.field-error :name="$name" :id="$id.'-error'" />
    @endif
</div>
