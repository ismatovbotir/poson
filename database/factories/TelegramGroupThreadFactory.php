<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramGroupThread>
 */
class TelegramGroupThreadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory()->tracked(),
            'role' => AgentRole::Sales,
            'topic' => 'Label printer',
            'summary' => 'The client asks about a label printer.',
            'next_step' => 'Offer a setup visit.',
            'draft' => null,
        ];
    }
}
