<?php

namespace App\Actions\Orders\Concerns;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The end of every order edit (code review #4): once all parts are applied
 * under the order lock, recompute the totals once (only while the lines may
 * change), check the payment matrix once (every unpaid order: its method
 * must still be allowed for the new buyer / delivery / total) and reissue
 * the invoice once when the total changed.
 *
 * Needs $recalculate (RecalculateOrderTotals), $paymentOptions
 * (PaymentOptions) and $reissue (ReissueInvoice) on the action.
 */
trait FinalizesOrderChange
{
    /**
     * @throws ValidationException
     */
    protected function finalizeOrderChange(Order $order, int $oldTotal, ?User $actor, string $errorKey): void
    {
        if ($order->isLinesEditable()) {
            $this->recalculate->handle($order);
        }

        if ($order->payment_status !== PaymentStatus::Unpaid) {
            return;
        }

        if (! $this->paymentOptions->allows($order->payment_method, $order->paymentContext())) {
            throw ValidationException::withMessages([$errorKey => __('orders.errors.method_not_allowed_after')]);
        }

        if ($order->total !== $oldTotal) {
            $this->reissue->handle($order, $actor, $oldTotal);
        }
    }
}
