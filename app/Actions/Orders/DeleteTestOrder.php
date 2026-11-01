<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\PaymentMode;
use App\Enums\UzqrSettlement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a TEST order with its lines, payment rows and history (plan 11 v2
 * §1.7, security condition 29), in one transaction. Real orders are never
 * deleted, not even by an Admin; any non-test payment row (or, from 11c /
 * 11d, a live-mode provider transaction) on the order is a hard failure.
 *
 * Dynamic UzQR (condition 35): a prod-mode row, a row with a pay_id while
 * no separate test host exists (the "test" money may have been real), a
 * settled row or an invoice still holding the order is a hard failure;
 * otherwise the test-mode rows go with the order.
 */
class DeleteTestOrder
{
    use LocksOrder;

    /**
     * @throws ValidationException
     */
    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = $this->lockOrder($order);

            if (! $order->is_test) {
                throw ValidationException::withMessages(['order' => __('orders.errors.delete_real')]);
            }

            if (OrderPayment::query()->where('order_id', $order->id)->where('is_test', false)->exists()) {
                throw ValidationException::withMessages(['order' => __('orders.errors.delete_real_payment')]);
            }

            $uzqr = UzqrTransaction::query()->where('order_id', $order->id);
            $testHost = app(UzqrConfig::class)->testAvailable();

            if ((clone $uzqr)->where('mode', '!=', PaymentMode::Test->value)->exists()
                || (clone $uzqr)->where('settlement', UzqrSettlement::Settled->value)->exists()
                || (! $testHost && (clone $uzqr)->whereNotNull('pay_id')->exists())
                || (clone $uzqr)->awaitingBank()->exists()) {
                throw ValidationException::withMessages(['order' => __('orders.errors.delete_real_payment')]);
            }

            (clone $uzqr)->delete();
            OrderPayment::query()->where('order_id', $order->id)->where('is_test', true)->delete();
            $order->activities()->delete();
            $order->items()->delete();
            $order->delete();
        });
    }
}
