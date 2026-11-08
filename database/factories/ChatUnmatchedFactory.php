<?php

namespace Database\Factories;

use App\Enums\ChatChannel;
use App\Models\ChatUnmatched;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatUnmatched>
 */
class ChatUnmatchedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = 'savol'.fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'channel' => ChatChannel::Telegram,
            'locale' => 'uz',
            'normalized' => $word,
            'sample' => $word,
            'count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
