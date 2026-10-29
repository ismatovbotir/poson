<?php

namespace App\Support\Orders\Contracts;

use App\Models\Order;

/**
 * A source of active online payment holds on an order (plan 11 v2 §1.6):
 * Payme state-1 transactions younger than 12 h (11c), Click prepares
 * younger than 60 min (11d), dynamic UzQR transactions (11e). Each
 * implementation is listed in config('checkout.payment_holds') and asked
 * by Order::hasActiveTransaction(), which gates line edits, payment method
 * changes, cancelling and ReleaseStalePaymentHolds.
 */
interface PaymentHold
{
    public function holds(Order $order): bool;
}
