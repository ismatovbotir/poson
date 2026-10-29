{{-- An order history entry (activities, type order / payment / notification). Payment details only with $canPayments. --}}
@php
    $meta = $activity->meta ?? [];
    $action = (string) ($meta['action'] ?? '');
    $money = fn ($tiyin) => is_numeric($tiyin) ? \App\Support\Money::format((int) $tiyin) : '';
    $status = fn ($value) => \App\Enums\OrderStatus::tryFrom((string) $value)?->label() ?? (string) $value;
    $method = fn ($value) => \App\Enums\PaymentMethod::tryFrom((string) $value)?->label() ?? '';
    $paymentActions = ['paid', 'delivery_charge_added', 'delivery_charge_cancelled', 'delivery_charge_paid', 'payment_voided', 'refunded', 'reconciled', 'unreconciled'];
@endphp
@if (in_array($action, $paymentActions, true) && ! $canPayments)
    {{ __('orders.timeline.payment_hidden') }}
@elseif ($action === 'created')
    {{ __('orders.timeline.created', ['total' => $money($meta['total'] ?? 0)]) }}@if (! empty($meta['test'])) · TEST @endif
@elseif ($action === 'status')
    {{ __('orders.timeline.status', ['from' => $status($meta['from'] ?? ''), 'to' => $status($meta['to'] ?? '')]) }}
@elseif ($action === 'paid' || $action === 'delivery_charge_paid')
    {{ __('orders.timeline.'.$action, ['method' => $method($meta['method'] ?? ''), 'amount' => $money($meta['amount'] ?? 0)]) }}@if (! empty($meta['reference'])) · {{ $meta['reference'] }}@endif
@elseif (in_array($action, ['delivery_charge_added', 'delivery_charge_cancelled', 'payment_voided', 'refunded', 'reconciled', 'unreconciled'], true))
    {{ __('orders.timeline.'.$action, ['amount' => $money($meta['amount'] ?? 0)]) }}
@elseif ($action === 'price_edited')
    {{ __('orders.timeline.price_edited', ['item' => (string) ($meta['item'] ?? ''), 'old' => isset($meta['old']) ? $money($meta['old']) : '—', 'new' => $money($meta['new'] ?? 0)]) }}@if (isset($meta['reference'])) · {{ __('orders.timeline.reference_price', ['price' => $money($meta['reference'])]) }}@endif
@elseif ($action === 'lines_updated')
    {{ __('orders.timeline.lines_updated', ['from' => $money($meta['old_total'] ?? 0), 'to' => $money($meta['new_total'] ?? 0)]) }}
@elseif ($action === 'invoice_reissued')
    {{ __('orders.timeline.invoice_reissued', ['version' => (int) ($meta['version'] ?? 1)]) }}
@elseif ($action === 'payment_method_changed')
    {{ __('orders.timeline.payment_method_changed', ['from' => $method($meta['from'] ?? ''), 'to' => $method($meta['to'] ?? '')]) }}
@elseif (in_array($action, ['details_updated', 'delivery_updated', 'link_renewed', 'customer_linked', 'customer_unlinked'], true))
    {{ __('orders.timeline.'.$action) }}
@elseif ($activity->type === \App\Enums\ActivityType::Notification)
    {{ __('orders.timeline.notification') }}
@else
    {{ $activity->type->label() }}
@endif
