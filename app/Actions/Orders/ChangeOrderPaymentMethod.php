<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\PaymentOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change how an order is paid (plan 11 v2 §1.7, security conditions 3 and
 * 7): orders.update, only while the lines may change (unpaid, no active
 * online transaction, new / confirmed) and only to a method the matrix
 * allows for the order (PaymentOptions). One provider per order: this is
 * the only way to switch. Logged.
 */
class ChangeOrderPaymentMethod
{
    use LocksOrder;

    public function __construct(protected PaymentOptions $paymentOptions, protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, string $method, User $actor): void
    {
        DB::transaction(function () use ($order, $method, $actor): void {
            $order = $this->lockOrder($order);
            $from = $order->payment_method;

            $this->apply($order, $method, $actor);

            if ($order->payment_method !== $from && ! $this->paymentOptions->allows($order->payment_method, $order->paymentContext())) {
                throw ValidationException::withMessages(['payment_method' => __('orders.errors.method_not_allowed')]);
            }
        });
    }

    /**
     * Apply to a locked order (the matrix check: the caller finalizes).
     *
     * @throws ValidationException
     */
    public function apply(Order $order, string $method, User $actor): void
    {
        $to = PaymentMethod::tryFrom($method);

        if ($to === null || $to->isReserved()) {
            throw ValidationException::withMessages(['payment_method' => __('orders.errors.method_not_allowed')]);
        }

        if ($order->payment_method === $to) {
            return;
        }

        if (! $order->isLinesEditable()) {
            throw ValidationException::withMessages(['payment_method' => __('orders.errors.lines_locked')]);
        }

        $from = $order->payment_method;
        $order->forceFill(['payment_method' => $to])->save();

        $this->log->handle($order, 'payment_method_changed', $actor, ['from' => $from->value, 'to' => $to->value]);
    }
}
