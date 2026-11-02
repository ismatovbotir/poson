<?php

namespace Database\Factories;

use App\Models\MonitoringState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringState>
 */
class MonitoringStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'heartbeat:'.fake()->unique()->word(),
            'ticked_at' => now(),
            'problem_since' => null,
            'alerted_at' => null,
            'meta' => null,
        ];
    }
}
