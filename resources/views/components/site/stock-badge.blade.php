@props(['status'])

{{-- Stock status (App\Enums\StockStatus) with the template's colored dot. --}}
@php
    $stockClass = match ($status) {
        \App\Enums\StockStatus::InStock => 'text-ok',
        \App\Enums\StockStatus::OnOrder => 'text-warn',
        \App\Enums\StockStatus::OutOfStock => 'text-ink-2',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 text-[13px] font-medium', $stockClass]) }} data-stock="{{ $status->value }}">
    <span class="size-[7px] rounded-full bg-current" aria-hidden="true"></span>
    {{ __('site.stock.'.$status->value) }}
</span>
