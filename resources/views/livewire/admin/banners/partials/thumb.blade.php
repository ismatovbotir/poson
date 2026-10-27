{{-- Desktop image thumbnail (WebP "thumb", 3.2 : 1); "processing" until the queued conversion exists. --}}
@php($thumb = $banner->imageUrl('thumb'))
<span class="grid h-10 w-32 shrink-0 place-items-center overflow-hidden rounded-md border border-slate-200 bg-canvas">
    @if ($thumb)
        <img src="{{ $thumb }}" alt="" width="128" height="40" loading="lazy" class="size-full object-cover" data-banner-thumb>
    @elseif ($banner->bannerImageProcessing())
        <span class="text-[10px] text-slate-500">{{ __('admin.image_upload.processing') }}</span>
    @else
        <x-admin.icon name="image" size="size-5" class="text-slate-300" />
    @endif
</span>
