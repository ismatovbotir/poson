<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->sentence(),
        ];
    }

    /**
     * Override a company setting, e.g. Setting::factory()->setting('email', 'sales@poson.uz').
     */
    public function setting(string $key, mixed $value): static
    {
        return $this->state(fn () => ['key' => $key, 'value' => $value]);
    }
}
