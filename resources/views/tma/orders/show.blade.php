{{--
    One Mini App order (OrderController::show): number, status, lines, delivery, Retail totals.
    Bank requisites (site.partials.order-bank) only once staff confirmed a bank transfer; the invoice
    PDF opens outside Telegram. Never the /o page or its UzQR component.
    Plan 45 §3: the "received" block with what happens next (tma.js: success haptic), and a
    server-rendered status stepper New → Confirmed → Shipped → Completed (Cancelled: a badge).
--}}
@extends('layouts.tma')

@php
    $money = fn (int $tiyin): string => \App\Support\Money::format($tiyin);
    $separate = $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate;
@endphp

@section('content')
    @if ($placed)
        <section class="tma-card grid gap-3" data-placed-note data-placed>
            <div class="flex items-center gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-tg-button text-tg-button-text"><x-tma.icon name="check" size="size-6" /></span>
                <p class="text-[17px] font-semibold">{{ __('tma.orders.received', ['number' => $order->number]) }}</p>
            </div>
            <p class="text-sm font-medium text-tg-header">{{ __('tma.orders.next_title') }}</p>
            <ol class="grid gap-2 text-sm">
                @foreach (['confirm', 'payment', 'delivery'] as $next)
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-tg-secondary text-xs font-semibold">{{ $loop->iteration }}</span> {{ __('tma.orders.next.'.$next) }}</li>
                @endforeach
            </ol>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('tma.orders') }}" class="tma-btn-outline">{{ __('tma.home.my_orders') }}</a>
                <a href="{{ route('tma.home') }}" class="tma-btn">{{ __('tma.orders.continue') }}</a>
            </div>
        </section>
    @endif

    @php
        $flow = [\App\Enums\OrderStatus::New, \App\Enums\OrderStatus::Confirmed, \App\Enums\OrderStatus::Shipped, \App\Enums\OrderStatus::Completed];
        // Processing sits between Confirmed and Shipped: shown as Confirmed reached.
        $reached = match ($order->status) {
            \App\Enums\OrderStatus::Processing => 1,
            default => array_search($order->status, $flow, true),
        };
    @endphp
    <section class="tma-card grid gap-3" data-order="{{ $order->id }}">
        <div class="grid gap-0.5">
            <p class="text-xl font-semibold">{{ __('tma.orders.number', ['number' => $order->number]) }}</p>
            <p class="text-xs text-tg-hint">{{ \App\Support\DateFormat::iso($order->created_at, 'D MMMM YYYY') }}</p>
        </div>
        <div data-order-status>
            <p class="sr-only">{{ __('tma.orders.status') }}: {{ $order->status->label() }}</p>
            @if ($order->status === \App\Enums\OrderStatus::Cancelled)
                <span class="tma-badge text-tg-danger" data-status-cancelled>{{ $order->status->label() }}</span>
            @else
                <ol class="grid grid-cols-4 gap-1 text-center text-xs" aria-hidden="true" data-status-stepper>
                    @foreach ($flow as $index => $status)
                        <li class="grid justify-items-center gap-1 {{ $index <= $reached ? 'font-semibold text-tg-link' : 'text-tg-hint' }}" @if ($index === $reached) data-current @endif>
                            <span class="grid size-6 place-items-center rounded-full {{ $index <= $reached ? 'bg-tg-button text-tg-button-text' : 'bg-tg-secondary' }}">
                                @if ($index < $reached)<x-tma.icon name="check" size="size-4" />@else{{ $index + 1 }}@endif
                            </span>
                            {{ $status->label() }}
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
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
