{{-- Product images (inside the product form: no <form>, Enter never submits the parent). --}}
<div class="p-4 sm:p-6" x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()" data-product-images>
    @if ($this->images->isEmpty() && ! $canEdit)
        <p class="text-sm text-slate-500">{{ __('admin.products.images.none') }}</p>
    @endif

    <ul @if ($canEdit) wire:sort="sortImage" @endif class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
        @foreach ($this->images as $media)
            @php($url = $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null)
            <li wire:key="image-{{ $media->id }}" @if ($canEdit) wire:sort:item="{{ $media->id }}" @endif x-data="{ alt: false }" @class(['relative rounded-xl border bg-white', 'border-2 border-brand-500' => $loop->first, 'border-slate-200' => ! $loop->first]) data-image="{{ $media->id }}">
                <div class="relative grid aspect-square place-items-center overflow-hidden rounded-t-xl bg-canvas">
                    @if ($url)
                        <img src="{{ $url }}" alt="{{ $product->imageAlt($media) }}" loading="lazy" class="size-full object-contain" data-image-thumb>
                    @else
                        <span class="px-2 text-center text-xs text-slate-500">{{ __('admin.image_upload.processing') }}</span>
                    @endif
                    @if ($loop->first)
                        <span class="absolute left-2 top-2 rounded-md bg-brand-600 px-1.5 py-0.5 text-[11px] font-semibold text-white">{{ __('admin.products.images.main') }}</span>
                    @elseif ($canEdit)
                        <span wire:sort:handle class="absolute left-1.5 top-1.5 hidden cursor-grab rounded bg-white/80 text-slate-400 sm:block" title="{{ __('admin.categories.drag') }}" aria-hidden="true"><x-admin.icon name="grip" size="size-4" /></span>
                    @endif
                    @if ($canEdit)
                        <button
                            type="button"
                            x-on:click="$dispatch('confirm', {
                                title: @js(__('admin.products.images.delete_title')),
                                body: @js(__('admin.products.images.delete_body')),
                                label: @js(__('admin.common.delete')),
                                component: $wire.$id, method: 'deleteImage', params: [{{ $media->id }}],
                            })"
                            class="absolute right-1.5 top-1.5 grid size-7 place-items-center rounded-md bg-white/90 text-slate-500 shadow-sm hover:text-danger"
                            aria-label="{{ __('admin.image_upload.remove') }}"
                        ><x-admin.icon name="trash" size="size-4" /></button>
                    @endif
                </div>
                @if ($canEdit)
                    <div class="flex items-center gap-1 border-t border-slate-100 p-1.5">
                        <button type="button" wire:click="moveImage({{ $media->id }}, -1)" @disabled($loop->first) class="grid size-8 place-items-center rounded text-slate-400 hover:bg-slate-100 disabled:opacity-30 sm:hidden" aria-label="{{ __('admin.common.move_up') }}"><x-admin.icon name="chevron-left" size="size-4" /></button>
                        <button type="button" wire:click="moveImage({{ $media->id }}, 1)" @disabled($loop->last) class="grid size-8 place-items-center rounded text-slate-400 hover:bg-slate-100 disabled:opacity-30 sm:hidden" aria-label="{{ __('admin.common.move_down') }}"><x-admin.icon name="chevron-right" size="size-4" /></button>
                        <button type="button" x-on:click="alt = !alt" x-bind:aria-expanded="alt.toString()" class="ml-auto inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                            {{ __('admin.image_upload.alt') }}
                            @if (collect($alts[$media->id] ?? [])->filter()->count() < count(config('app.supported_locales')))
                                <span class="size-1.5 rounded-full bg-warn" aria-hidden="true"></span>
                            @endif
                        </button>
                    </div>
                    <div x-cloak x-show="alt" class="space-y-2 border-t border-slate-100 p-2.5">
                        <p class="text-xs font-semibold text-slate-600">{{ __('admin.image_upload.alt') }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.products.images.alt_hint') }}</p>
                        @foreach (config('app.supported_locales') as $locale)
                            <div class="flex items-center gap-1.5">
                                <span class="w-6 shrink-0 text-center text-[11px] font-bold uppercase text-slate-400" aria-hidden="true">{{ $locale }}</span>
                                <input wire:model="alts.{{ $media->id }}.{{ $locale }}" lang="{{ $locale }}" maxlength="255" aria-label="{{ __('admin.image_upload.alt') }} ({{ strtoupper($locale) }})" class="h-9 w-full min-w-0 rounded-lg border border-slate-200 px-2.5 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm">
                            </div>
                        @endforeach
                        <button type="button" wire:click="saveAlt({{ $media->id }})" x-on:click="alt = false" class="h-9 w-full rounded-lg bg-brand-50 text-sm font-semibold text-brand-700 hover:bg-brand-100">{{ __('admin.common.save') }}</button>
                    </div>
                @endif
            </li>
        @endforeach

        @if ($canEdit)
            <li>
                <label class="relative grid aspect-square cursor-pointer place-items-center rounded-xl border-2 border-dashed border-slate-300 text-center text-slate-500 focus-within:ring-2 focus-within:ring-brand-500 hover:border-brand-500 hover:bg-brand-50/40 hover:text-brand-700">
                    <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" aria-describedby="product-images-hint">
                    <span wire:loading.remove wire:target="uploads"><x-admin.icon name="upload" size="size-6" class="mx-auto" /><span class="mt-1 block text-xs font-semibold">{{ __('admin.products.images.upload') }}</span></span>
                    <span wire:loading wire:target="uploads" class="text-xs font-semibold">{{ __('admin.image_upload.uploading') }}</span>
                </label>
            </li>
        @endif
    </ul>

    <p id="product-images-hint" class="mt-3 text-xs text-slate-500">{{ __('admin.products.images.hint') }}</p>
    @error('uploads')<x-admin.field-error name="uploads" />@enderror
    @foreach ($errors->get('uploads.*') as $messages)
        <span class="mt-1 flex items-center gap-1 text-xs text-danger"><x-admin.icon name="alert" size="size-3.5" />{{ $messages[0] }}</span>
    @endforeach
</div>
