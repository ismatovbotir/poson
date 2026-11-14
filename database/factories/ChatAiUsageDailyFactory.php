<?php

namespace Database\Factories;

use App\Enums\ChatChannel;
use App\Models\ChatAiUsageDaily;
use App\Support\Chat\WorkingHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatAiUsageDaily>
 */
class ChatAiUsageDailyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => now(WorkingHours::TIMEZONE)->toDateString(),
            'channel' => ChatChannel::Telegram,
            'requests' => 10,
            'answered' => 9,
            'failed' => 1,
            'input_tokens' => 12000,
            'output_tokens' => 800,
            'billed_tokens' => 12800,
        ];
    }
}
