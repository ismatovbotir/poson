<?php

namespace Database\Factories;

use App\Models\TrafficDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficDaily>
 */
class TrafficDailyFactory extends Factory
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
            'dimension' => TrafficDaily::DIM_TOTAL,
            'channel' => '',
            'value' => '',
            'visitors' => fake()->numberBetween(10, 100),
            'page_views' => fake()->numberBetween(100, 300),
            'entries' => fake()->numberBetween(10, 100),
            'leads' => fake()->numberBetween(0, 5),
        ];
    }

    public function bot(string $key, int $hits = 10): static
    {
        return $this->state([
            'kind' => TrafficDaily::KIND_BOTS,
            'dimension' => TrafficDaily::DIM_BOT,
            'value' => $key,
            'visitors' => 1,
            'page_views' => 0,
            'entries' => 0,
            'leads' => 0,
            'hits' => $hits,
            'last_seen_at' => now(),
        ]);
    }
}
