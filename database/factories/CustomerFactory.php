<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A company customer with a unique normalized phone, Retail price level and
 * no manager. States: individual(), managedBy(), priceLevel().
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CustomerType::Company,
            'name' => '“'.fake()->unique()->company().'” MChJ',
            'inn' => (string) fake()->unique()->numberBetween(200_000_000, 699_999_999),
            'phone' => '+99891'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->companyEmail(),
            'address' => null,
            'notes' => null,
            'price_level' => PriceLevel::Retail,
            'manager_id' => null,
            'telegram_id' => null,
            'locale' => 'uz',
        ];
    }

    public function individual(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            'inn' => null,
        ]);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(fn (array $attributes) => ['manager_id' => $manager->id]);
    }

    public function priceLevel(PriceLevel $level): static
    {
        return $this->state(fn (array $attributes) => ['price_level' => $level]);
    }
}
