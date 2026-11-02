<?php

namespace Database\Factories;

use App\Enums\VideoFetchStatus;
use App\Models\Product;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A pending, active video of a new product by default; owner() attaches it to
 * another owner, inactive() / unavailable() / withThumbnail() set the state.
 *
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'videoable_type' => 'product',
            'videoable_id' => Product::factory(),
            'youtube_id' => Str::random(11),
            'title' => null,
            'source_title' => null,
            'source_author' => null,
            'source_title_safe' => true,
            'published_on' => null,
            'fetch_status' => VideoFetchStatus::Pending,
            'fetched_at' => null,
            'thumbnail_at' => null,
            'sort' => 0,
            'is_active' => true,
        ];
    }

    public function owner(Model $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'videoable_type' => $owner->getMorphClass(),
            'videoable_id' => $owner->getKey(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'fetch_status' => VideoFetchStatus::Unavailable,
            'fetched_at' => now(),
        ]);
    }

    public function fetched(string $title = 'Zebra ZD421 setup'): static
    {
        return $this->state(fn (array $attributes) => [
            'fetch_status' => VideoFetchStatus::Ok,
            'source_title' => $title,
            'source_author' => 'Poson',
            'fetched_at' => now(),
        ]);
    }

    public function withThumbnail(): static
    {
        return $this->state(fn (array $attributes) => ['thumbnail_at' => now()]);
    }
}
