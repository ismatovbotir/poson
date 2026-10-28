<?php

namespace Database\Factories;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open, empty cart with a fresh ULID token.
 *
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => (string) Str::ulid(),
            'locale' => 'uz',
            'converted_order_id' => null,
            'converted_at' => null,
        ];
    }

    /**
     * An order was placed from it (plan 11b).
     */
    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'converted_at' => now(),
        ]);
    }
}
