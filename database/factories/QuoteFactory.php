<?php

namespace Database\Factories;

use App\Enums\PriceLevel;
use App\Enums\QuoteStatus;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A draft quote for a (new) customer without items (totals 0), valid for a
 * week. Rows are inserted directly (no CreateQuote: the number is random,
 * not from the sequence). States: status(), sent(), managedBy(), forLead(),
 * expiredBy().
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'Q-'.now()->year.'-'.fake()->unique()->numberBetween(1000, 99999),
            'ulid' => (string) Str::ulid(),
            'customer_id' => Customer::factory(),
            'status' => QuoteStatus::Draft,
            'price_level' => PriceLevel::Retail,
            'locale' => 'uz',
            'valid_until' => now()->addWeek()->toDateString(),
            'subtotal' => 0,
            'vat_amount' => 0,
            'total' => 0,
            'status_changed_at' => now(),
        ];
    }

    public function status(QuoteStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'sent_at' => $status === QuoteStatus::Draft ? null : now(),
            'accepted_at' => $status === QuoteStatus::Accepted ? now() : null,
        ]);
    }

    public function sent(): static
    {
        return $this->status(QuoteStatus::Sent);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(fn (array $attributes) => ['manager_id' => $manager->id, 'created_by_id' => $manager->id]);
    }

    /**
     * Valid until a past date.
     */
    public function pastValidity(int $daysAgo = 1): static
    {
        return $this->state(fn (array $attributes) => ['valid_until' => now()->subDays($daysAgo)->toDateString()]);
    }

    /**
     * Totals from a VAT-inclusive amount (tiyin).
     */
    public function totalling(int $total): static
    {
        return $this->state(fn (array $attributes) => ['subtotal' => $total, 'total' => $total, 'vat_amount' => Quote::vatOf($total)]);
    }
}
