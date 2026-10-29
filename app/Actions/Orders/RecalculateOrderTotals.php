<?php

namespace App\Actions\Orders;

use App\Actions\Quotes\SaveQuoteItems;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Orders\CheckoutSettings;
use App\Support\Vat;
use Illuminate\Validation\ValidationException;

/**
 * Recompute an order's totals from its lines (inside the caller's
 * transaction, under the order lock): subtotal = Σ line totals, total =
 * subtotal + delivery_amount (a fixed delivery; 0 when billed separately),
 * vat_amount = its VAT part. The total is capped by checkout.max_total.
 * Never call it on a paid order (its total is immutable).
 */
class RecalculateOrderTotals
{
    public function __construct(protected CheckoutSettings $settings) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order): void
    {
        $subtotal = 0;

        foreach (OrderItem::query()->where('order_id', $order->id)->pluck('total') as $lineTotal) {
            $subtotal += (int) $lineTotal;

            if ($subtotal > SaveQuoteItems::MAX_TOTAL) {
                throw ValidationException::withMessages(['items' => __('orders.errors.total_too_high')]);
            }
        }

        $total = $subtotal + $order->delivery_amount;

        if ($total > $this->settings->maxTotal()) {
            throw ValidationException::withMessages(['items' => __('orders.errors.total_too_high')]);
        }

        $order->forceFill([
            'subtotal' => $subtotal,
            'total' => $total,
            'vat_amount' => Vat::of($total),
        ])->save();
    }
}
