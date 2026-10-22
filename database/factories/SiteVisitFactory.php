<?php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use App\Models\SiteVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteVisit>
 */
class SiteVisitFactory extends Factory
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
            'type' => VisitType::PageView,
            'visitor_hash' => substr(hash('sha256', fake()->uuid()), 0, 16),
            'is_entry' => false,
            'channel' => TrafficChannel::Direct,
            'source' => null,
            'route_name' => 'site.home',
            'path' => '/uz',
            'locale' => 'uz',
            'device' => DeviceType::Desktop,
            'status' => 200,
        ];
    }

    public function entry(TrafficChannel $channel = TrafficChannel::Direct, ?string $source = null): static
    {
        return $this->state(['is_entry' => true, 'channel' => $channel, 'source' => $source]);
    }

    public function lead(TrafficChannel $channel = TrafficChannel::Direct, ?string $source = null): static
    {
        return $this->state(['type' => VisitType::Lead, 'channel' => $channel, 'source' => $source]);
    }
}
