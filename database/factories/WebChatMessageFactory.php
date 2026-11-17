<?php

namespace Database\Factories;

use App\Enums\ChatAuthor;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebChatMessage>
 */
class WebChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'web_chat_id' => WebChat::factory(),
            'author' => ChatAuthor::Visitor,
            'text' => fake()->sentence(),
        ];
    }

    public function bot(): static
    {
        return $this->state(fn () => ['author' => ChatAuthor::Bot]);
    }

    public function staff(): static
    {
        return $this->state(fn () => ['author' => ChatAuthor::Staff]);
    }
}
