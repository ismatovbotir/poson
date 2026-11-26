<?php

namespace Database\Factories;

use App\Enums\GroupStatus;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramGroup>
 */
class TelegramGroupFactory extends Factory
{
    /**
     * An untracked stub (no assignee, nothing stored).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_chat_id' => -1 * fake()->unique()->numberBetween(1_000_000_000, 999_999_999_999),
            'type' => 'supergroup',
            'title' => fake()->company(),
            'status' => GroupStatus::Untracked,
            'bot_status' => 'member',
            'seen_count' => 1,
            'last_message_at' => now()->subHour(),
        ];
    }

    public function tracked(?User $assignee = null): static
    {
        return $this->state(fn () => [
            'status' => GroupStatus::Tracked,
            'assignee_id' => $assignee?->id,
            'tracked_at' => now()->subHour(),
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn () => ['status' => GroupStatus::Ignored]);
    }

    public function untracked(): static
    {
        return $this->state(fn () => ['status' => GroupStatus::Untracked]);
    }

    public function botAdmin(): static
    {
        return $this->state(fn () => ['bot_status' => 'administrator', 'bot_is_admin' => true]);
    }
}
