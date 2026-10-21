<span class="grid {{ $size }} shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-100 bg-canvas">
    @if ($url = $brand->imageUrl())
        <img src="{{ $url }}" alt="{{ $brand->imageAlt() ?: $brand->name }}" loading="lazy" class="size-full object-contain p-1" data-brand-logo>
    @else
        <span class="text-sm font-bold text-slate-400" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand->name, 0, 2)) }}</span>
    @endif
</span>
