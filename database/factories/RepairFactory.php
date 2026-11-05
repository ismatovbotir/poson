<?php

namespace Database\Factories;

use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;
use App\Enums\RepairStatus;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A received, unassigned, paid repair with a unique number and phone. Rows
 * are inserted directly (no CreateRepair: no activity). States: assignedTo(),
 * status(), warranty(), estimated(), approved(), forCustomer().
 *
 * @extends Factory<Repair>
 */
class RepairFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'R-2026-'.fake()->unique()->numerify('######'),
            'status' => RepairStatus::Received,
            'locale' => 'uz',
            'contact_name' => fake()->name(),
            'contact_phone' => '+99890'.fake()->unique()->numerify('#######'),
            'company' => fake()->company(),
            'device_type' => RepairDeviceType::Scanner,
            'device_brand' => 'Zebra',
            'device_model' => 'DS2208',
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
            'intake_condition' => 'Scratches on the housing, cable included.',
            'problem' => 'Does not read barcodes.',
            'coverage' => RepairCoverage::Paid,
            'received_at' => now(),
            'status_changed_at' => now(),
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => ['assigned_to_id' => $user->id]);
    }

    public function status(RepairStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
        ]);
    }

    public function warranty(string $reference = 'Invoice 123 of 2026-01-10'): static
    {
        return $this->state(fn (array $attributes) => ['coverage' => RepairCoverage::Warranty, 'warranty_reference' => $reference]);
    }

    public function estimated(int $tiyin = 15_000_000): static
    {
        return $this->state(fn (array $attributes) => ['estimate_amount' => $tiyin]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_amount' => $attributes['estimate_amount'] ?? 15_000_000,
            'estimate_approved_at' => now(),
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => ['customer_id' => $customer->id]);
    }
}
