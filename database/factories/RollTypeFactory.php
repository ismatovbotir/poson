<?php

namespace Database\Factories;

use App\Models\RollType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RollType>
 */
class RollTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique pair: a unique labels count is enough.
            'labels_per_roll' => fake()->unique()->numberBetween(100, 99_999),
            'core_diameter_mm' => fake()->randomElement(['12.7', '25.0', '40.0', '76.0']),
            'is_active' => true,
            'sort' => 0,
        ];
    }

    /**
     * A fixed labels / core pair.
     */
    public function size(int $labelsPerRoll, string $coreDiameterMm): static
    {
        return $this->state(fn (array $attributes) => [
            'labels_per_roll' => $labelsPerRoll,
            'core_diameter_mm' => $coreDiameterMm,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
