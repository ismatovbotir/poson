<?php

namespace Database\Factories;

use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\ChatStatDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatStatDaily>
 */
class ChatStatDailyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => today(),
            'channel' => ChatChannel::Telegram,
            'metric' => ChatMetric::Hit,
            'chat_rule_id' => 0,
            'count' => 1,
        ];
    }
}
