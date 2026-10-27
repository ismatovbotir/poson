<?php

namespace Database\Factories;

use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Models\NewsPost;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published news post (yesterday), translated in uz, ru and en.
 * draft() / published() / scheduled() / untranslated('ru', 'en') adjust it;
 * article() makes it a knowledge article (kind = article).
 *
 * @extends Factory<NewsPost>
 */
class NewsPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'slug' => Slug::make($title).'-'.fake()->unique()->numberBetween(1, 999_999),
            'kind' => NewsKind::News,
            'status' => NewsStatus::Published,
            'published_at' => now()->subDay(),
            'title' => ['uz' => ucfirst($title), 'ru' => 'RU '.$title, 'en' => 'EN '.$title],
            'excerpt' => ['uz' => 'Qisqacha '.$title.'.', 'ru' => 'Кратко '.$title.'.', 'en' => 'In short '.$title.'.'],
            'body' => ['uz' => '<p>Matn '.$title.'.</p>', 'ru' => '<p>Текст '.$title.'.</p>', 'en' => '<p>Text '.$title.'.</p>'],
            'meta_title' => null,
            'meta_description' => null,
            'author_id' => null,
            'content_updated_at' => null,
        ];
    }

    public function article(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => NewsKind::Article]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => NewsStatus::Draft, 'published_at' => null]);
    }

    /**
     * Published at the given moment (default: yesterday).
     */
    public function published(mixed $at = null): static
    {
        return $this->state(fn (array $attributes) => ['status' => NewsStatus::Published, 'published_at' => $at ?? now()->subDay()]);
    }

    /**
     * Published with a future date (default: in three days).
     */
    public function scheduled(mixed $at = null): static
    {
        return $this->state(fn (array $attributes) => ['status' => NewsStatus::Published, 'published_at' => $at ?? now()->addDays(3)]);
    }

    /**
     * Without title, excerpt and body in the given locales (default ru and en).
     */
    public function untranslated(string ...$locales): static
    {
        $locales = $locales === [] ? ['ru', 'en'] : $locales;

        return $this->state(function (array $attributes) use ($locales): array {
            $state = [];

            foreach (['title', 'excerpt', 'body'] as $field) {
                $state[$field] = array_diff_key((array) ($attributes[$field] ?? []), array_flip($locales));
            }

            return $state;
        });
    }
}
