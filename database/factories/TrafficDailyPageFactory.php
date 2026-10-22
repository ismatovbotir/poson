<?php

namespace Database\Factories;

use App\Models\TrafficDaily;
use App\Models\TrafficDailyPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficDailyPage>
 */
class TrafficDailyPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'kind' => TrafficDaily::KIND_VISITS,
            'bot' => '',
            'path' => '/uz/'.fake()->unique()->slug(2),
            'status' => 200,
            'hits' => fake()->numberBetween(1, 50),
            'visitors' => fake()->numberBetween(1, 20),
        ];
    }
}
