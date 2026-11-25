<?php

namespace Database\Factories;

use App\Enums\BusinessChatKind;
use App\Models\BusinessChat;
use App\Models\BusinessConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessChat>
 */
class BusinessChatFactory extends Factory
{
    /**
     * An unmarked attention stub with one waiting message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_connection_id' => BusinessConnection::factory(),
            'telegram_chat_id' => fake()->unique()->numberBetween(9_990_010_000, 9_990_099_999),
            'kind' => BusinessChatKind::Unmarked,
            'first_name' => fake()->firstName(),
            'username' => null,
            'unread_count' => 1,
            'first_inbound_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
            'last_message_at' => now()->subHour(),
        ];
    }

    public function client(?User $assignee = null): static
    {
        return $this->state(fn () => [
            'kind' => BusinessChatKind::Client,
            'assignee_id' => $assignee?->id,
            'marked_at' => now()->subHour(),
            'unread_count' => 0,
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn () => ['kind' => BusinessChatKind::Employee, 'marked_at' => now(), 'unread_count' => 0]);
    }

    public function ignored(): static
    {
        return $this->state(fn () => ['kind' => BusinessChatKind::Ignored, 'marked_at' => now(), 'unread_count' => 0]);
    }
}
