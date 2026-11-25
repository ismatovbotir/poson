<?php

namespace Database\Factories;

use App\Enums\BusinessAuthor;
use App\Enums\TelegramMessageKind;
use App\Models\BusinessChat;
use App\Models\BusinessMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessMessage>
 */
class BusinessMessageFactory extends Factory
{
    /**
     * A client's text message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_chat_id' => BusinessChat::factory()->client(),
            'telegram_message_id' => fake()->unique()->numberBetween(1, 900_000),
            'author' => BusinessAuthor::Client,
            'kind' => TelegramMessageKind::Text,
            'text' => fake()->sentence(),
            'sent_at' => now()->subMinutes(20),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['author' => BusinessAuthor::Owner]);
    }
}
