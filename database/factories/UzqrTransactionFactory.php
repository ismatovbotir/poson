<?php

namespace Database\Factories;

use App\Enums\PaymentMode;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Models\Order;
use App\Models\UzqrTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fake dynamic UzQR invoices (never real bank ids or payer data).
 *
 * @extends Factory<UzqrTransaction>
 */
class UzqrTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => PaymentMode::Prod,
            'order_id' => Order::factory(),
            'external_id' => null,
            'invoice_id' => 'INV'.strtoupper(Str::random(12)),
            'amount' => 100_000_00,
            'status' => UzqrTransactionStatus::Pending,
            'remote_status' => 1,
            'qr_text' => 'https://qr.example.test/'.Str::random(16),
            'expires_at' => now()->addMinutes(24 * 60 - 2),
            'next_check_at' => now()->addMinute(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (UzqrTransaction $transaction): void {
            if ($transaction->external_id === null) {
                $transaction->forceFill(['external_id' => UzqrTransaction::externalIdFor($transaction->id, $transaction->mode)])->save();
            }
        });
    }

    public function testMode(): static
    {
        return $this->state(fn (array $attributes) => ['mode' => PaymentMode::Test]);
    }

    public function creating(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UzqrTransactionStatus::Creating, 'invoice_id' => null, 'qr_text' => null, 'remote_status' => null, 'expires_at' => null]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UzqrTransactionStatus::Failed, 'remote_status' => 3, 'closed_at' => now(), 'next_check_at' => null]);
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UzqrTransactionStatus::Paid,
            'remote_status' => 2,
            'pay_id' => 'PAY'.strtoupper(Str::random(17)),
            'settlement' => UzqrSettlement::Settled,
            'paid_at' => now(),
            'closed_at' => now(),
            'next_check_at' => null,
        ]);
    }

    public function unmatched(string $reason = 'amount_mismatch'): static
    {
        return $this->settled()->state(fn (array $attributes) => ['settlement' => UzqrSettlement::Unmatched, 'unmatched_reason' => $reason]);
    }
}
