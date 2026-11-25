{{--
    One Mini App order (OrderController::show): number, status, lines, delivery, Retail totals.
    Bank requisites (site.partials.order-bank) only once staff confirmed a bank transfer; the invoice
    PDF opens outside Telegram. Never the /o page or its UzQR component.
--}}
@extends('layouts.tma')

@php
    $money = fn (int $tiyin): string => \App\Support\Money::format($tiyin);
    $separate = $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate;
@endphp

@section('content')
    @if ($placed)
        <p class="tma-card text-sm" data-placed-note>{{ __('tma.orders.placed_note') }}</p>
    @endif

    <section class="tma-card grid gap-1" data-order="{{ $order->id }}">
        <p class="text-lg font-semibold">{{ __('tma.orders.number', ['number' => $order->number]) }}</p>
        <p class="text-sm" data-order-status>{{ __('tma.orders.status') }}: <span class="font-medium">{{ $order->status->label() }}</span></p>
        <p class="text-xs text-tg-hint">{{ \App\Support\DateFormat::iso($order->created_at, 'D MMMM YYYY') }}</p>
    </section>

    <section class="tma-card grid gap-1.5 text-sm" data-order-items>
        @foreach ($order->items as $item)
            <div class="flex justify-between gap-3">
                <span>{{ $item->name }}@if ($item->isRollLine()), {{ $item->rollDescription(app()->getLocale()) }}@else × {{ $item->qty }}@endif</span>
                <span class="shrink-0">{{ $money($item->total) }}</span>
            </div>
        @endforeach
        <div class="mt-1 flex justify-between border-t border-tg-separator pt-2">
            <span>{{ __('tma.orders.delivery') }}: {{ $order->delivery_type->label() }}@if ($order->region) · {{ $order->region->label() }}@endif</span>
            <span class="shrink-0">{{ $separate && $order->delivery_amount === 0 ? __('tma.checkout.region_quoted') : $money($order->delivery_amount) }}</span>
        </div>
        <div class="flex justify-between text-base font-semibold"><span>{{ __('tma.orders.total') }}</span><span data-order-total>{{ $money($order->total) }}</span></div>
        <p class="text-xs text-tg-hint">{{ __('tma.orders.payment') }}: {{ $order->payment_method->label() }}</p>
    </section>

    @if ($bank !== null)
        <section class="tma-card text-sm" data-bank>
            <h2 class="font-semibold">{{ __('tma.orders.bank_title') }}</h2>
            @include('site.partials.order-bank', $bank)
            @if ($invoiceUrl !== null)
                <a href="{{ $invoiceUrl }}" data-open-link="{{ $invoiceUrl }}" target="_blank" rel="noopener noreferrer" class="tma-btn-outline mt-3" data-invoice>{{ __('tma.orders.invoice') }}</a>
            @endif
        </section>
    @elseif ($order->payment_method === \App\Enums\PaymentMethod::BankTransfer && $order->status === \App\Enums\OrderStatus::New)
        <p class="tma-card text-sm text-tg-hint" data-bank-pending>{{ __('tma.orders.bank_pending') }}</p>
    @endif

    @if ($botLink !== null)
        <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-card text-center text-sm font-medium text-tg-link" data-contact-manager>{{ __('tma.contact_manager') }}</a>
    @endif
@endsection
