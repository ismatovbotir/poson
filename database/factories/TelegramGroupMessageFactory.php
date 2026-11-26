<?php

namespace Database\Factories;

use App\Enums\TelegramMessageKind;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramGroupMessage>
 */
class TelegramGroupMessageFactory extends Factory
{
    /**
     * A text message of a member (the member's group is the message's group).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => TelegramGroupMember::factory(),
            'telegram_group_id' => fn (array $attributes): int => TelegramGroupMember::query()->findOrFail($attributes['member_id'])->telegram_group_id,
            'telegram_message_id' => fake()->unique()->numberBetween(1, 900_000),
            'kind' => TelegramMessageKind::Text,
            'text' => fake()->sentence(),
            'sent_at' => now()->subMinutes(20),
        ];
    }

    public function inGroup(TelegramGroup $group): static
    {
        return $this->state(fn () => [
            'telegram_group_id' => $group->id,
            'member_id' => TelegramGroupMember::factory()->state(['telegram_group_id' => $group->id]),
        ]);
    }
}
