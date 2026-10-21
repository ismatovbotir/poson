{{-- Product main image thumbnail (needs "media" eager-loaded). --}}
@php($thumbUrl = $product->imageUrl())
<span class="grid {{ $size ?? 'size-11' }} shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-100 bg-canvas">
    @if ($thumbUrl)
        <img src="{{ $thumbUrl }}" alt="" loading="lazy" class="size-full object-contain p-0.5" data-product-thumb>
    @else
        <x-admin.icon name="image" size="size-5" class="text-slate-300" />
    @endif
</span>
