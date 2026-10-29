<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\AppliesDelivery;
use App\Actions\Orders\Concerns\ValidatesOrderFields;
use App\Data\Orders\ManualOrderData;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PriceLevel;
use App\Events\Orders\OrderPlaced;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\OrderNumber;
use App\Support\Orders\PaymentOptions;
use App\Support\Quotes\WorkingDays;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create an order in admin (plan 11 v2 §1.5, §1.8): orders.create (the
 * caller authorizes; a TEST order also needs payments.test, re-checked
 * here). source manual; the customer's price level prices new lines
 * (fallback Retail) and staff may edit prices within the floor
 * (SaveOrderItems, condition 26). The delivery is quoted like the site's
 * (or a fee typed by staff), the payment method must pass the matrix (TEST
 * orders: online providers only in test mode). Number O-… or T-… (TEST),
 * public link for 90 days (TEST orders have none: /o is a 404 for them).
 * OrderPlaced after commit (sales chat, "🧪 TEST" for test orders).
 */
class CreateManualOrder
{
    use AppliesDelivery, ValidatesOrderFields;

    public function __construct(
        protected SaveOrderItems $saveItems,
        protected RecalculateOrderTotals $recalculate,
        protected CheckoutSettings $settings,
        protected PaymentOptions $paymentOptions,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ManualOrderData $data, User $actor): Order
    {
        if ($data->isTest && ! $actor->can('payments.test')) {
            throw ValidationException::withMessages(['is_test' => __('orders.errors.test_admin_only')]);
        }

        $details = $this->validatedDetails($data->details);
        $delivery = $this->validatedDelivery($data->delivery);
        $method = PaymentMethod::tryFrom($data->paymentMethod);

        if ($method === null || $method->isReserved()) {
            throw ValidationException::withMessages(['payment_method' => __('orders.errors.method_not_allowed')]);
        }

        $customer = $data->customerId !== null ? Customer::query()->find($data->customerId) : null;

        if ($data->customerId !== null && ! $customer instanceof Customer) {
            throw ValidationException::withMessages(['customer_id' => __('orders.errors.customer_missing')]);
        }

        $locale = in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : (string) config('app.locale');

        $order = DB::transaction(function () use ($data, $actor, $details, $delivery, $method, $customer, $locale): Order {
            $order = new Order([
                'number' => OrderNumber::next($data->isTest),
                'ulid' => strtoupper((string) Str::ulid()),
                'public_until' => $data->isTest ? null : now()->addDays((int) config('checkout.public_link_days', 90)),
                'is_test' => $data->isTest,
                'source' => OrderSource::Manual,
                'customer_id' => $customer?->id,
                'manager_id' => $details['manager_id'] ?? $actor->id,
                'created_by_id' => $actor->id,
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_method' => $method,
                'price_level' => $customer?->price_level ?? PriceLevel::Retail,
                'locale' => $locale,
                ...array_diff_key($details, ['manager_id' => true]),
                ...$delivery,
                'invoice_valid_until' => WorkingDays::add(now(), (int) config('checkout.invoice_valid_working_days', 5))->toDateString(),
                'status_changed_at' => now(),
            ]);

            $this->applyDeliveryPrice($order, $delivery['delivery_type'], $delivery['region'], $data->delivery->deliveryFee, $this->settings);
            $order->save();

            $edits = $this->saveItems->handle($order, $data->lines, $actor);
            $this->recalculate->handle($order);

            if (! $this->paymentOptions->allows($method, $order->paymentContext())) {
                throw ValidationException::withMessages(['payment_method' => __('orders.errors.method_not_allowed')]);
            }

            $this->log->handle($order, 'created', $actor, ['source' => OrderSource::Manual->value, 'total' => $order->total, 'test' => $data->isTest ?: null]);

            foreach ($edits as $edit) {
                $this->log->handle($order, 'price_edited', $actor, [
                    'item' => mb_substr($edit['name'], 0, 120),
                    'old' => $edit['old'],
                    'new' => $edit['new'],
                    'reference' => $edit['reference'],
                ]);
            }

            return $order;
        });

        OrderPlaced::dispatch($order);

        return $order;
    }
}
