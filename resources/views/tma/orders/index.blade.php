{{-- "My orders" (OrderController::index, plan 45 §2): the user's Mini App orders, newest first, with a status badge, the item count and the first item. --}}
@extends('layouts.tma')

@section('content')
    @if ($orders->isNotEmpty())
        <div class="grid gap-2">
        @foreach ($orders as $order)
        <a href="{{ route('tma.orders.show', ['order' => $order->id]) }}" class="tma-card grid gap-1" data-order="{{ $order->id }}">
            <span class="flex items-center justify-between gap-3">
                <span class="font-medium">{{ __('tma.orders.number', ['number' => $order->number]) }}</span>
                <span class="tma-badge {{ $order->status === \App\Enums\OrderStatus::Cancelled ? 'text-tg-danger' : 'text-tg-link' }}" data-order-status>{{ $order->status->label() }}</span>
            </span>
            @if ($order->items->isNotEmpty())
                <span class="truncate text-sm text-tg-subtitle" data-order-first-item>{{ $order->items->first()->name }}@if ($order->items->count() > 1) {{ __('tma.orders.more_items', ['count' => $order->items->count() - 1]) }}@endif</span>
            @endif
            <span class="flex items-center justify-between gap-3 text-xs text-tg-hint">
                <span>{{ \App\Support\DateFormat::iso($order->created_at, 'D MMMM YYYY') }} · {{ __('tma.orders.items', ['count' => $order->items->count()]) }}</span>
                <span class="text-sm font-semibold text-tg-text">{{ \App\Support\Money::format($order->total) }}</span>
            </span>
        </a>
        @endforeach
        </div>
    @else
        <section class="tma-group grid justify-items-center gap-3 py-6 text-center" data-orders-empty>
            <x-tma.icon name="doc" size="size-8" class="text-tg-hint" />
            <p class="text-tg-hint">{{ __('tma.orders.empty') }}</p>
            <a href="{{ route('tma.home') }}" class="tma-btn">{{ __('tma.cart.to_catalog') }}</a>
        </section>
    @endif
@endsection
