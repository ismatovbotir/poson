<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A text spec value by default; use number(), boolean() or option() for the
 * other attribute types.
 *
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->words(2, true);

        return [
            'product_id' => Product::factory(),
            'attribute_id' => Attribute::factory(),
            'attribute_option_id' => null,
            'value_text' => ['uz' => $text],
            'value_number' => null,
            'value_bool' => null,
            'is_key' => false,
            'sort' => 0,
        ];
    }

    public function number(float|int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'value_text' => null,
            'value_number' => $number,
        ]);
    }

    public function boolean(bool $value = true): static
    {
        return $this->state(fn (array $attributes) => [
            'value_text' => null,
            'value_bool' => $value,
        ]);
    }

    /**
     * A select / multiselect value (the option's attribute).
     */
    public function option(AttributeOption $option): static
    {
        return $this->state(fn (array $attributes) => [
            'attribute_id' => $option->attribute_id,
            'attribute_option_id' => $option->id,
            'value_text' => null,
        ]);
    }

    public function key(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_key' => true,
        ]);
    }
}
