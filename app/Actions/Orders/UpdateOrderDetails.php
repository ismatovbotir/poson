<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\FinalizesOrderChange;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Actions\Orders\Concerns\ValidatesOrderFields;
use App\Data\Orders\OrderDetailsData;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\PaymentOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit the buyer's contact, company, tax id, comment, staff note and
 * manager (plan 11 v2 §1.7): orders.update, before the order is completed
 * and not cancelled. An unpaid order's payment method must stay allowed
 * for the buyer (e.g. bank transfer needs a company or sole proprietor).
 * Logged with the names of the changed fields (never the values).
 *
 * apply() is the part UpdateOrder combines with the other edits.
 */
class UpdateOrderDetails
{
    use FinalizesOrderChange, LocksOrder, ValidatesOrderFields;

    public function __construct(
        protected LogOrderActivity $log,
        protected RecalculateOrderTotals $recalculate,
        protected PaymentOptions $paymentOptions,
        protected ReissueInvoice $reissue,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderDetailsData $data, User $actor): void
    {
        DB::transaction(function () use ($order, $data, $actor): void {
            $order = $this->lockOrder($order);
            $oldTotal = $order->total;

            $this->apply($order, $data, $actor);
            $this->finalizeOrderChange($order, $oldTotal, $actor, 'buyer_type');
        });
    }

    /**
     * Apply to a locked order (no matrix check: the caller finalizes).
     *
     * @throws ValidationException
     */
    public function apply(Order $order, OrderDetailsData $data, User $actor): void
    {
        $values = $this->validatedDetails($data);

        if (! $order->isDetailsEditable()) {
            throw ValidationException::withMessages(['name' => __('orders.errors.closed')]);
        }

        $order->fill($values);
        $changed = array_keys($order->getDirty());

        if ($changed === []) {
            return;
        }

        $order->save();

        $this->log->handle($order, 'details_updated', $actor, ['fields' => $changed]);
    }
}
