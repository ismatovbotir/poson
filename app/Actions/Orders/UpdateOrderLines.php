<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\AppliesDelivery;
use App\Actions\Orders\Concerns\FinalizesOrderChange;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Data\Orders\OrderLineData;
use App\Enums\DeliveryType;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change an order's lines and (region orders) its fixed delivery fee (plan
 * 11 v2 §1.7, security conditions 3, 6, 26, 27): orders.update, only while
 * unpaid, without an active online transaction and still new / confirmed.
 * Site orders are priced from the catalog (Retail); manual orders may carry
 * staff prices within the floor (SaveOrderItems). Totals are recomputed,
 * the payment method must still be allowed, every price edit is logged
 * (old / new / reference) and a changed total reissues the invoice.
 */
class UpdateOrderLines
{
    use AppliesDelivery, FinalizesOrderChange, LocksOrder;

    public function __construct(
        protected SaveOrderItems $saveItems,
        protected RecalculateOrderTotals $recalculate,
        protected CheckoutSettings $settings,
        protected PaymentOptions $paymentOptions,
        protected ReissueInvoice $reissue,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @param  list<OrderLineData>  $lines
     * @param  int|null  $deliveryFee  tiyin; a new fixed fee for a region order (null: unchanged)
     *
     * @throws ValidationException
     */
    public function handle(Order $order, array $lines, User $actor, ?int $deliveryFee = null): void
    {
        DB::transaction(function () use ($order, $lines, $actor, $deliveryFee): void {
            $order = $this->lockOrder($order);
            $oldTotal = $order->total;

            $this->apply($order, $lines, $actor, $deliveryFee);
            $this->finalizeOrderChange($order, $oldTotal, $actor, 'items');

            if ($order->total !== $oldTotal) {
                $this->log->handle($order, 'lines_updated', $actor, ['old_total' => $oldTotal, 'new_total' => $order->total]);
            }
        });
    }

    /**
     * Apply to a locked order (totals, matrix and invoice: the caller finalizes).
     *
     * @param  list<OrderLineData>  $lines
     *
     * @throws ValidationException
     */
    public function apply(Order $order, array $lines, User $actor, ?int $deliveryFee = null): void
    {
        if (! $order->isLinesEditable()) {
            throw ValidationException::withMessages(['items' => __('orders.errors.lines_locked')]);
        }

        $edits = $this->saveItems->handle($order, $lines, $actor);

        if ($deliveryFee !== null && $order->delivery_type === DeliveryType::CourierRegion && $deliveryFee !== $order->delivery_amount) {
            $this->applyDeliveryPrice($order, $order->delivery_type, $order->region, $deliveryFee, $this->settings);
            $order->save();
        }

        foreach ($edits as $edit) {
            $this->log->handle($order, 'price_edited', $actor, [
                'item' => mb_substr($edit['name'], 0, 120),
                'old' => $edit['old'],
                'new' => $edit['new'],
                'reference' => $edit['reference'],
            ]);
        }
    }
}
