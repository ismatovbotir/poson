<?php

namespace Database\Factories;

use App\Models\BotHit;
use App\Support\Analytics\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotHit>
 */
class BotHitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'bot' => 'gptbot',
            'ip' => inet_pton(fake()->ipv4()),
            'verified' => null,
            'route_name' => 'site.home',
            'path' => '/uz',
            'status' => 200,
        ];
    }

    public function fromIp(string $ip): static
    {
        return $this->state(['ip' => IpAddress::pack($ip)]);
    }
}
