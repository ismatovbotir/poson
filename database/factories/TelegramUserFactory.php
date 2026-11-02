<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Enums\TelegramUserState;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUser>
 */
class TelegramUserFactory extends Factory
{
    /**
     * Define the model's default state: a visitor who just pressed /start.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(100_000_000, 9_000_000_000),
            'username' => fake()->unique()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'locale' => 'uz',
            'state' => TelegramUserState::Language,
            'conversation_status' => ConversationStatus::Open,
            'unread_count' => 0,
        ];
    }

    public function unregistered(): static
    {
        return $this->state(fn () => [
            'state' => TelegramUserState::Contact,
            'phone' => null,
            'name' => null,
            'registered_at' => null,
            'consent_at' => null,
        ]);
    }

    public function registered(): static
    {
        return $this->state(fn () => [
            'state' => TelegramUserState::Registered,
            'phone' => '+998901234567',
            'name' => fake()->name(),
            'consent_at' => now()->subDay(),
            'registered_at' => now()->subDay(),
        ]);
    }

    public function assigned(User $manager): static
    {
        return $this->state(fn () => ['manager_id' => $manager->id]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['conversation_status' => ConversationStatus::Closed, 'closed_at' => now()]);
    }

    public function banned(?User $by = null): static
    {
        return $this->state(fn () => ['banned_at' => now(), 'banned_by_id' => $by?->id]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['blocked_at' => now()]);
    }
}
