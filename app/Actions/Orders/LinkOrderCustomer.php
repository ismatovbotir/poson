<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\TaxId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Link an order to a CRM customer (plan 11 v2 §1.4, security condition 11).
 *
 * findMatch(): the automatic link at checkout, only when the 9-digit STIR
 * equals one customer's INN AND the phone is that customer's own or one of
 * its contacts' phones; otherwise none. Linking never changes prices
 * (public orders stay Retail) and nothing of the customer is shown publicly.
 *
 * handle(): staff link (or unlink) a customer by hand (orders.update),
 * logged in the history; the order's prices are unchanged.
 */
class LinkOrderCustomer
{
    use LocksOrder;

    public function __construct(protected LogOrderActivity $log) {}

    public function findMatch(?string $tin, ?string $phone): ?int
    {
        $stir = TaxId::stir($tin);

        if ($stir === null || $phone === null || $phone === '') {
            return null;
        }

        $customers = Customer::query()->where('inn', $stir)->limit(2)->get(['id', 'phone']);

        if ($customers->count() !== 1) {
            return null;
        }

        $customer = $customers->first();

        if ($customer->phone === $phone || Contact::query()->where('customer_id', $customer->id)->where('phone', $phone)->exists()) {
            return $customer->id;
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, ?Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($order, $customer, $actor): void {
            $order = $this->lockOrder($order);

            if (! $order->isDetailsEditable()) {
                throw ValidationException::withMessages(['customer' => __('orders.errors.closed')]);
            }

            if ($order->customer_id === $customer?->id) {
                return;
            }

            $order->forceFill(['customer_id' => $customer?->id])->save();

            $this->log->handle($order, $customer !== null ? 'customer_linked' : 'customer_unlinked', $actor, ['customer_id' => $customer?->id]);
        });
    }
}
