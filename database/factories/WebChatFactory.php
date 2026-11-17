<?php

namespace Database\Factories;

use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebChat>
 */
class WebChatFactory extends Factory
{
    /**
     * Define the model's default state: a visitor who just sent a first message (bot mode, consent given).
     * The plain token is never stored; withToken() sets the hash of a known token for tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => WebChatToken::hash(WebChatToken::generate()),
            'locale' => 'en',
            'consent_at' => now(),
            'consent_version' => '2026-11-16',
            'mode' => ChatMode::Bot,
            'conversation_status' => ConversationStatus::Open,
            'unread_count' => 0,
            'last_message_at' => now(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'first_page' => '/en',
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => WebChatToken::hash($token)]);
    }

    public function handedOff(): static
    {
        return $this->state(fn () => [
            'mode' => ChatMode::Manager,
            'handoff_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => 1,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['manager_id' => $user->id]);
    }

    public function withContact(string $phone = '+998901234567', ?string $name = null): static
    {
        return $this->state(fn () => ['phone' => $phone, 'name' => $name ?? fake()->firstName()]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['banned_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['conversation_status' => ConversationStatus::Closed, 'closed_at' => now()]);
    }
}
