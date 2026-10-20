<?php

namespace Database\Factories;

use App\Models\Category;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => ['uz' => ucfirst($name), 'ru' => 'RU '.$name, 'en' => 'EN '.$name],
            'description' => null,
            'slug' => Slug::make($name).'-'.fake()->unique()->numberBetween(1, 999_999),
            'icon' => null,
            'sort' => 0,
            'is_active' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A subcategory of the given (or a new) category.
     */
    public function child(?Category $parent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent?->id ?? Category::factory(),
        ]);
    }

    /**
     * Names in the given locales only (others left out), e.g. ['uz' => 'Skanerlar'].
     *
     * @param  array<string, string>  $name
     */
    public function named(array $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }
}
