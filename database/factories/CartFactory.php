<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\TelegramUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open, empty site cart with a fresh ULID token. forTelegramUser(): a
 * Telegram Mini App cart (plan 20).
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

    /**
     * A Telegram Mini App cart of the user (a new registered user by default).
     */
    public function forTelegramUser(?TelegramUser $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_user_id' => $user?->id ?? TelegramUser::factory()->registered(),
        ]);
    }
}
