<?php

namespace Database\Factories;

use App\Enums\GroupMemberRole;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramGroupMember>
 */
class TelegramGroupMemberFactory extends Factory
{
    /**
     * A member whose role is not set yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory()->tracked(),
            'telegram_id' => fake()->unique()->numberBetween(100_000_000, 9_999_999_999),
            'role' => GroupMemberRole::Unknown,
            'is_bot' => false,
            'first_name' => fake()->firstName(),
            'member_status' => 'member',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ];
    }

    public function employee(): static
    {
        return $this->state(fn () => ['role' => GroupMemberRole::Employee, 'role_set_at' => now()]);
    }

    public function client(): static
    {
        return $this->state(fn () => ['role' => GroupMemberRole::Client, 'role_set_at' => now()]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => ['role' => GroupMemberRole::Unknown]);
    }

    public function bot(): static
    {
        return $this->state(fn () => ['role' => GroupMemberRole::Bot, 'is_bot' => true, 'username' => 'helper_bot']);
    }
}
