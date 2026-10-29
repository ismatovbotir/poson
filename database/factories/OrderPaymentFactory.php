<?php

namespace Database\Factories;

use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A paid bank-transfer goods payment of a (new) order. States:
 * deliveryCharge() (pending delivery charge), method().
 *
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = 'PP-'.fake()->unique()->numerify('######');

        return [
            'order_id' => Order::factory(),
            'purpose' => OrderPaymentPurpose::Goods,
            'method' => PaymentMethod::BankTransfer,
            'status' => OrderPaymentStatus::Paid,
            'amount' => 1_000_000_00,
            'is_test' => false,
            'reference' => $reference,
            'reference_key' => OrderPayment::referenceKey(PaymentMethod::BankTransfer, $reference),
            'paid_on' => now()->toDateString(),
            'paid_at' => now(),
        ];
    }

    /**
     * A pending delivery charge (no method yet).
     */
    public function deliveryCharge(int $amount = 150_000_00): static
    {
        return $this->state(fn (array $attributes) => [
            'purpose' => OrderPaymentPurpose::Delivery,
            'method' => null,
            'status' => OrderPaymentStatus::Pending,
            'amount' => $amount,
            'reference' => null,
            'reference_key' => null,
            'paid_on' => null,
            'paid_at' => null,
        ]);
    }

    public function method(PaymentMethod $method): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => $method,
            'reference_key' => $attributes['reference'] ?? null ? OrderPayment::referenceKey($method, (string) $attributes['reference']) : null,
        ]);
    }
}
