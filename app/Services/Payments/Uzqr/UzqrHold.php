<?php

namespace App\Services\Payments\Uzqr;

use App\Models\Order;
use App\Models\UzqrTransaction;
use App\Support\Orders\Contracts\PaymentHold;

/**
 * Dynamic UzQR holds an order while an invoice is creating, pending, or
 * expired without a bank-confirmed final status (condition 40): lines,
 * total, method, delivery fee, mark-paid, void, cancel and reissue are all
 * refused meanwhile, because the bank's status carries no amount.
 */
class UzqrHold implements PaymentHold
{
    public function holds(Order $order): bool
    {
        return UzqrTransaction::query()->where('order_id', $order->id)->holding()->exists();
    }
}
