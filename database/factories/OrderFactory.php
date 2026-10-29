<?php

namespace Database\Factories;

use App\Enums\BuyerType;
use App\Enums\DeliveryPricing;
use App\Enums\DeliveryType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PriceLevel;
use App\Enums\Region;
use App\Models\Order;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A new, unpaid site order (on receipt, pickup) of an individual without
 * lines (totals 0; use totalling() or add OrderItem rows), with a valid
 * public link. Rows are inserted directly (number random, not from the
 * sequence). States: totalling(), paid(), test(), bankTransfer(), uzqr(),
 * region(), separateDelivery(), company(), status(), manual(), expiredLink().
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'O-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'ulid' => strtoupper((string) Str::ulid()),
            'public_until' => now()->addDays(90),
            'is_test' => false,
            'source' => OrderSource::Site,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Unpaid,
            'payment_method' => PaymentMethod::OnReceipt,
            'price_level' => PriceLevel::Retail,
            'locale' => 'uz',
            'buyer_type' => BuyerType::Individual,
            'name' => fake()->name(),
            'phone' => '+99890'.fake()->numerify('#######'),
            'delivery_type' => DeliveryType::Pickup,
            'delivery_pricing' => DeliveryPricing::Fixed,
            'delivery_amount' => 0,
            'subtotal' => 0,
            'vat_amount' => 0,
            'total' => 0,
            'invoice_valid_until' => now()->addWeek()->toDateString(),
            'status_changed_at' => now(),
        ];
    }

    /**
     * Goods subtotal (tiyin) plus a fixed delivery amount.
     */
    public function totalling(int $subtotal, int $delivery = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'subtotal' => $subtotal,
            'delivery_amount' => $delivery,
            'total' => $subtotal + $delivery,
            'vat_amount' => Vat::of($subtotal + $delivery),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => ['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status, 'status_changed_at' => now()]);
    }

    /**
     * A TEST order created by an Admin (number T-…).
     */
    public function test(): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => 'T-'.now()->year.'-'.fake()->unique()->numberBetween(10000, 99999),
            'is_test' => true,
            'source' => OrderSource::Manual,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => ['source' => OrderSource::Manual]);
    }

    public function company(string $tin = '301234567', string $name = 'Mega Savdo MChJ'): static
    {
        return $this->state(fn (array $attributes) => ['buyer_type' => BuyerType::Company, 'company_name' => $name, 'tin' => $tin]);
    }

    public function bankTransfer(): static
    {
        return $this->company()->state(fn (array $attributes) => ['payment_method' => PaymentMethod::BankTransfer]);
    }

    public function uzqr(): static
    {
        return $this->state(fn (array $attributes) => ['payment_method' => PaymentMethod::UzqrStatic]);
    }

    /**
     * Courier to a region with a fixed fee (tiyin) already in the total.
     */
    public function region(Region $region = Region::Samarkand): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_type' => DeliveryType::CourierRegion,
            'region' => $region,
            'address' => 'Samarqand sh., Registon koʻchasi 1',
            // On receipt isn't offered for regions: keep any other method, else static UzQR.
            'payment_method' => ($attributes['payment_method'] ?? null) === PaymentMethod::OnReceipt ? PaymentMethod::UzqrStatic : $attributes['payment_method'],
        ]);
    }

    /**
     * Courier to a region without a fee: delivery billed separately.
     */
    public function separateDelivery(Region $region = Region::Khorezm): static
    {
        return $this->region($region)->state(fn (array $attributes) => ['delivery_pricing' => DeliveryPricing::Separate, 'delivery_amount' => 0]);
    }

    public function expiredLink(): static
    {
        return $this->state(fn (array $attributes) => ['public_until' => now()->subDay()]);
    }
}
