<?php

namespace Database\Factories;

use App\Enums\PageTemplate;
use App\Models\Page;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A custom, active default-template page; system() / legal() / contacts() /
 * inactive() / needsReview() adjust it.
 *
 * @extends Factory<Page>
 */
class PageFactory extends Factory
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
            'template' => PageTemplate::Default,
            'title' => ['uz' => ucfirst($title), 'ru' => 'RU '.$title, 'en' => 'EN '.$title],
            'body' => ['uz' => '<p>Matn '.$title.'.</p>', 'ru' => '<p>Текст '.$title.'.</p>', 'en' => '<p>Text '.$title.'.</p>'],
            'meta_title' => null,
            'meta_description' => null,
            'is_system' => false,
            'is_active' => true,
            'needs_review' => false,
            'sort' => 0,
        ];
    }

    /**
     * A system page with its fixed slug and template (Page::SYSTEM).
     */
    public function system(string $slug = 'about'): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => $slug,
            'template' => Page::SYSTEM[$slug] ?? PageTemplate::Default,
            'is_system' => true,
        ]);
    }

    public function legal(): static
    {
        return $this->state(fn (array $attributes) => ['template' => PageTemplate::Legal]);
    }

    public function contacts(): static
    {
        return $this->state(fn (array $attributes) => ['template' => PageTemplate::Contacts]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (array $attributes) => ['needs_review' => true]);
    }
}
