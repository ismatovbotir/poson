<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\AppliesDelivery;
use App\Actions\Orders\Concerns\FinalizesOrderChange;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Actions\Orders\Concerns\ValidatesOrderFields;
use App\Data\Orders\OrderDeliveryData;
use App\Enums\DeliveryPricing;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the delivery type, region, address or the Kokand address check
 * (plan 11 v2 §1.2, §1.7, security condition 19, review M4): orders.update,
 * before the order is shipped.
 *
 * - while the lines may change (unpaid, no active transaction, new /
 *   confirmed): the delivery is re-quoted (fixed fee or billed separately,
 *   or a fee typed by staff; separate → fixed is refused while a delivery
 *   charge waits), the totals recomputed, and a changed total reissues the
 *   invoice;
 * - paid: the total never changes; a change of type or region makes the
 *   delivery "separate", so staff bill the difference as a delivery charge
 *   (the delivery already paid stays in the total and is shown);
 * - unpaid but locked (processing, or held online): the price stays.
 * Every unpaid order's payment method must stay allowed (e.g. on receipt
 * never goes with a region courier). A changed address clears the Kokand
 * address check (it is confirmed again on the call).
 *
 * apply() is the part UpdateOrder combines with the other edits.
 */
class UpdateOrderDelivery
{
    use AppliesDelivery, FinalizesOrderChange, LocksOrder, ValidatesOrderFields;

    public function __construct(
        protected CheckoutSettings $settings,
        protected PaymentOptions $paymentOptions,
        protected RecalculateOrderTotals $recalculate,
        protected ReissueInvoice $reissue,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderDeliveryData $data, User $actor): void
    {
        DB::transaction(function () use ($order, $data, $actor): void {
            $order = $this->lockOrder($order);
            $oldTotal = $order->total;

            $this->apply($order, $data, $actor);
            $this->finalizeOrderChange($order, $oldTotal, $actor, 'delivery_type');
        });
    }

    /**
     * Apply to a locked order (totals, matrix and invoice: the caller finalizes).
     *
     * @throws ValidationException
     */
    public function apply(Order $order, OrderDeliveryData $data, User $actor): void
    {
        $values = $this->validatedDelivery($data);

        if (! $order->isDeliveryEditable()) {
            throw ValidationException::withMessages(['delivery_type' => __('orders.errors.delivery_locked')]);
        }

        $routeChanged = $order->delivery_type !== $values['delivery_type'] || $order->region !== $values['region'];

        // A new address is checked again on the confirmation call (review L3).
        if ((string) $order->address !== (string) $values['address']) {
            $values['kokand_address_confirmed'] = false;
        }

        $order->fill($values);

        if ($order->isLinesEditable()) {
            if ($routeChanged || $data->deliveryFee !== null) {
                $this->applyDeliveryPrice($order, $values['delivery_type'], $values['region'], $data->deliveryFee, $this->settings);
            }
        } elseif ($routeChanged && $order->payment_status === PaymentStatus::Paid) {
            $order->delivery_pricing = DeliveryPricing::Separate;
        }

        if (! $order->isDirty()) {
            return;
        }

        $changed = array_keys($order->getDirty());
        $order->save();

        $this->log->handle($order, 'delivery_updated', $actor, [
            'fields' => $changed,
            'delivery_type' => $order->delivery_type->value,
            'region' => $order->region?->value,
            'pricing' => $order->delivery_pricing->value,
        ]);
    }
}
