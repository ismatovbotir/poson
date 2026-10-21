{{-- Product cell: thumbnail, name, SKU · type (· brand). --}}
@php($label = $product->name)
@if ($linked)
    <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="flex items-center gap-3">
@else
    <span class="flex items-center gap-3">
@endif
    @include('livewire.admin.products.partials.thumb', ['product' => $product, 'size' => 'size-11'])
    <span class="min-w-0">
        <span @class(['block max-w-[16rem] truncate font-semibold text-slate-900 xl:max-w-[22rem]', 'hover:text-brand-700' => $linked])>{{ $label }}</span>
        <span class="block text-xs text-slate-500"><span class="font-mono">{{ $product->sku }}</span> · {{ $product->type->label() }}@if ($product->brand) · {{ $product->brand->name }}@endif</span>
    </span>
@if ($linked)
    </a>
@else
    </span>
@endif
