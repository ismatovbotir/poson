<?php

namespace App\Actions\Orders\Concerns;

use App\Enums\DeliveryPricing;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\DeliveryType;
use App\Enums\Region;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use Illuminate\Validation\ValidationException;

/**
 * Put the delivery price on an order whose total may still change: a fee
 * typed by staff for a region order (0..checkout.max_delivery_charge), else
 * the quote from Settings → Checkout (fixed fee or billed separately).
 * The caller recomputes the totals.
 */
trait AppliesDelivery
{
    /**
     * @throws ValidationException
     */
    protected function applyDeliveryPrice(Order $order, DeliveryType $type, ?Region $region, ?int $fee, CheckoutSettings $settings): void
    {
        if ($fee !== null && $type === DeliveryType::CourierRegion) {
            if ($fee < 0 || $fee > $settings->maxDeliveryCharge()) {
                throw ValidationException::withMessages(['delivery_fee' => __('orders.errors.delivery_amount')]);
            }

            $pricing = DeliveryPricing::Fixed;
            $amount = $fee;
        } else {
            $quote = DeliveryQuote::for($type, $region, $settings);
            $pricing = $quote->pricing;
            $amount = $quote->amount;
        }

        // Separate → fixed while a delivery charge waits for payment would bill
        // the delivery twice (code review #1): settle or cancel the charge first.
        if ($order->exists && $order->getOriginal('delivery_pricing') === DeliveryPricing::Separate && $pricing === DeliveryPricing::Fixed && OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('purpose', OrderPaymentPurpose::Delivery->value)
            ->where('status', OrderPaymentStatus::Pending->value)
            ->exists()) {
            throw ValidationException::withMessages(['delivery_type' => __('orders.errors.delivery_pending_exists')]);
        }

        $order->forceFill([
            'delivery_pricing' => $pricing,
            'delivery_amount' => $amount,
            'delivery_ikpu' => $amount > 0 ? $settings->deliveryIkpu() : null,
            'delivery_package_code' => $amount > 0 ? $settings->deliveryPackageCode() : null,
        ]);
    }
}
