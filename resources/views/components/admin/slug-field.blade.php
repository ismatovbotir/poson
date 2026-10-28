@props([
    'prefix',
    'slug' => null,
    'preview' => '',
    'hint' => null,
    'lockedHint' => null,
    'id' => 'f-form-slug',
])

{{--
    Read-only URL (slug) of a record. The slug is never typed by hand:
    - stored slug: shown locked, it never changes (renames keep it);
    - no slug yet: preview of the slug the Save action will generate,
      re-rendered whenever the source name field syncs.
    id: base element id; pass a unique one when the field is rendered more than once
      (e.g. once per locale panel).
    Errors from the action (e.g. a slug taken concurrently) show under it.
--}}
@php
    $locked = filled($slug);
    $value = $locked ? $slug : $preview;
    $hasError = $errors->has('form.slug');
@endphp

<div {{ $attributes->class(['block']) }}>
    <p id="{{ $id }}-label" class="text-sm font-semibold">{{ __('admin.catalog.slug') }}</p>
    <div
        id="{{ $id }}"
        role="group"
        aria-labelledby="{{ $id }}-label"
        aria-describedby="{{ $id }}-hint{{ $hasError ? ' '.$id.'-error' : '' }}"
        @if ($locked) data-slug-locked @else data-slug-preview @endif
        @class([
            'mt-1.5 flex min-h-11 items-center gap-2 overflow-hidden rounded-lg border bg-slate-50 px-3 py-2 font-mono text-sm',
            'border-slate-200 text-slate-600' => ! $hasError,
            'border-red-300 text-slate-600' => $hasError,
        ])
    >
        <x-admin.icon :name="$locked ? 'lock' : 'link'" size="size-4" class="shrink-0 text-slate-400" />
        <span class="min-w-0 break-all"><span class="text-slate-400">{{ $prefix }}</span><span @class(['font-sans italic text-slate-400' => blank($value)]) data-slug-value>{{ filled($value) ? $value : __('admin.catalog.slug_auto') }}</span></span>
    </div>
    <p id="{{ $id }}-hint" class="mt-1 text-xs text-slate-500">{{ $locked ? ($lockedHint ?? __('admin.catalog.slug_locked_hint')) : $hint }}</p>
    <x-admin.field-error name="form.slug" :id="$id.'-error'" />
</div>
