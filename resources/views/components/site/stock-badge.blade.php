@props(['status'])

{{--
    Stock status (App\Enums\StockStatus) with the template's colored dot.
    Plan 43 #80: the text is ink (the green/orange text was below AA at 13px); only the dot is coloured.
--}}
@php
    $dotClass = match ($status) {
        \App\Enums\StockStatus::InStock => 'bg-ok',
        \App\Enums\StockStatus::OnOrder => 'bg-warn',
        \App\Enums\StockStatus::OutOfStock => 'bg-ink-3',
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 text-[13px] font-medium text-ink') }} data-stock="{{ $status->value }}">
    <span class="size-[7px] rounded-full {{ $dotClass }}" aria-hidden="true"></span>
    {{ __('site.stock.'.$status->value) }}
</span>
