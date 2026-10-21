@props([
    'label' => null,
    'model',
    'file' => null,
    'currentUrl' => null,
    'removeAction' => null,
    'processing' => false,
    'altModel' => null,
    'hint' => null,
])

{{--
    Single image upload with preview, remove and alt text per locale (plan 03 D10).

    model:        wire:model of the upload (Livewire temporary file), e.g. "form.image"
    file:         that property's value, for the preview
    currentUrl:   URL of the saved image's conversion (null when none or marked for removal)
    processing:   an image is saved but its queued conversions aren't ready yet
    removeAction: Livewire method that clears the upload / marks the saved image for removal
    altModel:     array property with uz/ru/en alt text, e.g. "form.image_alt"
--}}
@php
    $id = 'f-'.str_replace(['.', '[', ']'], '-', $model);
    $preview = null;

    if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
        try {
            $preview = $file->isPreviewable() ? $file->temporaryUrl() : null;
        } catch (\Throwable) {
            $preview = null;
        }
    }

    $preview ??= $currentUrl;
@endphp

<div {{ $attributes->class(['block']) }}>
    @if ($label)
        <span class="text-sm font-semibold">{{ $label }}</span>
    @endif

    <div @class(['flex items-start gap-3', 'mt-2' => $label])>
        <div class="relative grid size-28 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-canvas">
            @if ($preview)
                <img src="{{ $preview }}" alt="" class="size-full object-contain" data-image-preview>
            @elseif ($processing)
                <span class="px-2 text-center text-xs text-slate-500" data-image-processing>{{ __('admin.image_upload.processing') }}</span>
            @else
                <x-admin.icon name="image" size="size-8" class="text-slate-300" />
            @endif

            <div wire:loading.flex wire:target="{{ $model }}" class="absolute inset-0 items-center justify-center bg-white/80">
                <svg class="size-6 animate-spin text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <span class="sr-only">{{ __('admin.image_upload.uploading') }}</span>
            </div>

            @if (($preview || $processing) && $removeAction)
                <button type="button" wire:click="{{ $removeAction }}" class="absolute right-1.5 top-1.5 grid size-7 place-items-center rounded-md bg-white/90 text-slate-500 shadow-sm hover:text-danger" aria-label="{{ __('admin.image_upload.remove') }}"><x-admin.icon name="trash" size="size-4" /></button>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <label for="{{ $id }}" class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg border-2 border-dashed border-slate-300 px-4 text-sm font-semibold text-slate-700 focus-within:ring-2 focus-within:ring-brand-500 hover:border-brand-500 hover:bg-brand-50/40 hover:text-brand-700">
                <input id="{{ $id }}" type="file" wire:model="{{ $model }}" accept="image/jpeg,image/png,image/webp" class="sr-only" @if ($hint) aria-describedby="{{ $id }}-hint" @endif>
                <x-admin.icon name="upload" size="size-4" />
                {{ $preview || $processing ? __('admin.image_upload.replace') : __('admin.image_upload.choose') }}
            </label>
            <p id="{{ $id }}-hint" class="mt-2 text-xs text-slate-500">{{ $hint ?? __('admin.image_upload.hint') }}</p>
            <x-admin.field-error :name="$model" />
        </div>
    </div>

    @if ($altModel)
        <fieldset class="mt-4">
            <legend class="flex items-center gap-1 text-xs font-semibold text-slate-600">
                {{ __('admin.image_upload.alt') }}
                <x-admin.ai-button :field="$altModel" />
            </legend>
            <div class="mt-1.5 space-y-2">
                @foreach (config('app.supported_locales') as $locale)
                    <div class="flex items-center gap-2">
                        <span class="w-7 shrink-0 text-center text-[11px] font-bold uppercase text-slate-400" aria-hidden="true">{{ $locale }}</span>
                        <x-admin.input
                            wire:model="{{ $altModel }}.{{ $locale }}"
                            lang="{{ $locale }}"
                            maxlength="255"
                            class="flex-1"
                            aria-label="{{ __('admin.image_upload.alt') }} ({{ __('admin.locale.'.$locale, [], $locale) }})"
                        />
                    </div>
                @endforeach
            </div>
        </fieldset>
    @endif
</div>
