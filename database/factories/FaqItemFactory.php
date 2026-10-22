<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * A general (site-wide) FAQ item by default; for() / forCategory() /
 * forProduct() attach it to an owner, inactive() hides it.
 *
 * @extends Factory<FaqItem>
 */
class FaqItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'faqable_type' => null,
            'faqable_id' => null,
            'question' => [
                'uz' => "Savol {$number}?",
                'ru' => "Вопрос {$number}?",
                'en' => "Question {$number}?",
            ],
            'answer' => [
                'uz' => "Javob {$number}.",
                'ru' => "Ответ {$number}.",
                'en' => "Answer {$number}.",
            ],
            'sort' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Attach the item to a category or product.
     */
    public function owner(Model $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'faqable_type' => $owner->getMorphClass(),
            'faqable_id' => $owner->getKey(),
        ]);
    }

    public function forCategory(?Category $category = null): static
    {
        return $this->owner($category ?? Category::factory()->create());
    }

    public function forProduct(?Product $product = null): static
    {
        return $this->owner($product ?? Product::factory()->create());
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
