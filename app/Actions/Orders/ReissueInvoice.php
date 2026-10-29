<?php

namespace App\Actions\Orders;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Quotes\WorkingDays;
use Illuminate\Validation\ValidationException;

/**
 * Reissue an unpaid order's invoice (plan 11 v2 §1.7, security condition
 * 27): the invoice number gets the next version suffix ("O-2026-0001/2"),
 * a fresh validity (5 working days), and /o shows "Amount updated". Called
 * by the line / delivery edits when the total changes (inside their
 * transaction, under the order lock) and by staff by hand. Mark-paid always
 * checks the current total, so a payment of an old amount is a mismatch
 * the sales team resolves.
 */
class ReissueInvoice
{
    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, ?User $actor, ?int $oldTotal = null): void
    {
        if ($order->payment_status !== PaymentStatus::Unpaid) {
            throw ValidationException::withMessages(['invoice' => __('orders.errors.not_unpaid')]);
        }

        $order->forceFill([
            'invoice_version' => $order->invoice_version + 1,
            'invoice_valid_until' => WorkingDays::add(now(), (int) config('checkout.invoice_valid_working_days', 5))->toDateString(),
            'amount_updated_at' => $oldTotal !== null && $oldTotal !== $order->total ? now() : $order->amount_updated_at,
        ])->save();

        $this->log->handle($order, 'invoice_reissued', $actor, [
            'version' => $order->invoice_version,
            'old_total' => $oldTotal,
            'new_total' => $oldTotal !== null ? $order->total : null,
        ]);
    }
}
