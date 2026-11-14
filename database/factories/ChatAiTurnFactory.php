<?php

namespace Database\Factories;

use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatAiTurn>
 */
class ChatAiTurnFactory extends Factory
{
    /**
     * Define the model's default state: a customer turn on Telegram.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => ChatChannel::Telegram,
            'telegram_user_id' => TelegramUser::factory()->registered(),
            'role' => ChatAiRole::User,
            'text' => 'Qaysi printer kerak?',
        ];
    }

    /**
     * An assistant answer to the given user turn.
     */
    public function answering(ChatAiTurn $turn, ChatAiStatus $status = ChatAiStatus::Answered): static
    {
        return $this->state(fn (): array => [
            'channel' => $turn->channel,
            'telegram_user_id' => $turn->telegram_user_id,
            'answers_turn_id' => $turn->id,
            'role' => ChatAiRole::Assistant,
            'text' => $status->isDelivered() ? 'Poson yordam beradi.' : '',
            'status' => $status,
            'model' => 'claude-haiku-5-5',
            'input_tokens' => 1200,
            'output_tokens' => 80,
        ]);
    }
}
