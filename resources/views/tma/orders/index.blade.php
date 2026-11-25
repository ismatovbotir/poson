{{-- "My orders" (OrderController::index): the user's Mini App orders, newest first. --}}
@extends('layouts.tma')

@section('content')
    @forelse ($orders as $order)
        <a href="{{ route('tma.orders.show', ['order' => $order->id]) }}" class="tma-card flex items-center justify-between gap-3" data-order="{{ $order->id }}">
            <span class="grid gap-0.5">
                <span class="font-medium">{{ __('tma.orders.number', ['number' => $order->number]) }}</span>
                <span class="text-xs text-tg-hint">{{ \App\Support\DateFormat::iso($order->created_at, 'D MMMM YYYY') }}</span>
            </span>
            <span class="grid justify-items-end gap-0.5 text-sm">
                <span class="font-semibold">{{ \App\Support\Money::format($order->total) }}</span>
                <span class="text-xs text-tg-hint" data-order-status>{{ $order->status->label() }}</span>
            </span>
        </a>
    @empty
        <section class="tma-card grid gap-3 text-center" data-orders-empty>
            <p class="text-tg-hint">{{ __('tma.orders.empty') }}</p>
            <a href="{{ route('tma.home') }}" class="tma-btn">{{ __('tma.cart.to_catalog') }}</a>
        </section>
    @endforelse
@endsection
