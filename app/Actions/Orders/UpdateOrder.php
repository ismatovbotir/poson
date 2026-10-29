<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\FinalizesOrderChange;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\PaymentOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the admin order form (code review #4, L1): in one transaction under
 * the order lock, apply each part the editing rules allow — buyer details
 * (and the CRM customer link), delivery, lines, payment method — and then
 * recompute the totals once, check the payment matrix once and reissue the
 * invoice at most once. So combined changes (e.g. pickup + on receipt →
 * region courier + bank transfer) are judged on the final state.
 */
class UpdateOrder
{
    use FinalizesOrderChange, LocksOrder;

    public function __construct(
        protected UpdateOrderDetails $details,
        protected UpdateOrderDelivery $delivery,
        protected UpdateOrderLines $lines,
        protected ChangeOrderPaymentMethod $method,
        protected RecalculateOrderTotals $recalculate,
        protected PaymentOptions $paymentOptions,
        protected ReissueInvoice $reissue,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @param  list<OrderLineData>  $lines
     *
     * @throws ValidationException
     */
    public function handle(Order $order, OrderDetailsData $details, ?Customer $customer, OrderDeliveryData $delivery, array $lines, string $paymentMethod, User $actor): void
    {
        DB::transaction(function () use ($order, $details, $customer, $delivery, $lines, $paymentMethod, $actor): void {
            $order = $this->lockOrder($order);
            $oldTotal = $order->total;
            $linesEditable = $order->isLinesEditable();

            if ($order->isDetailsEditable()) {
                $this->details->apply($order, $details, $actor);

                if ($order->customer_id !== $customer?->id) {
                    $order->forceFill(['customer_id' => $customer?->id])->save();
                    $this->log->handle($order, $customer !== null ? 'customer_linked' : 'customer_unlinked', $actor, ['customer_id' => $customer?->id]);
                }
            }

            if ($order->isDeliveryEditable()) {
                $this->delivery->apply($order, $linesEditable ? $delivery : new OrderDeliveryData($delivery->deliveryType, $delivery->region, $delivery->address, $delivery->kokandConfirmed), $actor);
            }

            if ($linesEditable) {
                $this->lines->apply($order, $lines, $actor);
                $this->method->apply($order, $paymentMethod, $actor);
            }

            $this->finalizeOrderChange($order, $oldTotal, $actor, 'payment_method');
        });
    }
}
