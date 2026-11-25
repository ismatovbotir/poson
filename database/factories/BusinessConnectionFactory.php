<?php

namespace Database\Factories;

use App\Models\BusinessConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessConnection>
 */
class BusinessConnectionFactory extends Factory
{
    /**
     * An enabled, allowed connection of a synthetic owner id (fake range 99900xxxxx).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->unique()->numberBetween(9_990_000_001, 9_990_009_999);

        return [
            'connection_id' => 'fake-conn-'.fake()->unique()->bothify('??##??##'),
            'owner_telegram_id' => $owner,
            'user_chat_id' => $owner,
            'is_enabled' => true,
            'is_allowed' => true,
            'can_reply' => false,
            'rights' => ['can_read_messages' => true],
            'connected_at' => now(),
        ];
    }

    public function disallowed(): static
    {
        return $this->state(fn () => ['is_allowed' => false]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false, 'disabled_at' => now()]);
    }
}
