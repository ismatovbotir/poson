<?php

namespace Database\Factories;

use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramMessage>
 */
class TelegramMessageFactory extends Factory
{
    /**
     * Define the model's default state: an inbound text message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_user_id' => TelegramUser::factory(),
            'direction' => TelegramDirection::In,
            'kind' => TelegramMessageKind::Text,
            'text' => fake()->sentence(),
            'telegram_message_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'status' => TelegramMessageStatus::Received,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => TelegramDirection::In, 'status' => TelegramMessageStatus::Received, 'user_id' => null]);
    }

    public function outbound(?User $author = null): static
    {
        return $this->state(fn () => [
            'direction' => TelegramDirection::Out,
            'status' => TelegramMessageStatus::Sent,
            'sent_at' => now(),
            'user_id' => $author?->id,
        ]);
    }

    public function failed(int $errorCode = 403): static
    {
        return $this->state(fn () => ['status' => TelegramMessageStatus::Failed, 'error_code' => $errorCode]);
    }

    public function media(TelegramMessageKind $kind = TelegramMessageKind::Document): static
    {
        return $this->state(fn () => [
            'kind' => $kind,
            'text' => null,
            'media' => ['file_name' => 'requisites.pdf', 'mime_type' => 'application/pdf', 'file_size' => 20480],
            'forward_status' => TelegramForwardStatus::Pending,
        ]);
    }

    public function forwarded(): static
    {
        return $this->state(fn () => [
            'forward_status' => TelegramForwardStatus::Forwarded,
            'forwarded_message_id' => fake()->numberBetween(1, 1_000_000),
        ]);
    }
}
