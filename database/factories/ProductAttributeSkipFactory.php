<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttributeSkip>
 */
class ProductAttributeSkipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attribute_id' => Attribute::factory(),
        ];
    }
}
