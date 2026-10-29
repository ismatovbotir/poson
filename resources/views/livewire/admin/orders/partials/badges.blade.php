{{-- Order status + payment status badges (and TEST). --}}
<span class="inline-flex flex-wrap items-center gap-1.5">
    @if ($order->is_test)<x-admin.badge color="red" data-test-badge>🧪 TEST</x-admin.badge>@endif
    <x-admin.badge :color="$order->status->color()" dot pill data-status="{{ $order->status->value }}">{{ $order->status->label() }}</x-admin.badge>
    <x-admin.badge :color="$order->payment_status->color()" dot pill data-payment-status="{{ $order->payment_status->value }}">{{ $order->payment_status->label() }}</x-admin.badge>
</span>
