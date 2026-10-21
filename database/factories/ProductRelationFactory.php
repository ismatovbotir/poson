<?php

namespace Database\Factories;

use App\Enums\RelationType;
use App\Models\Product;
use App\Models\ProductRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRelation>
 */
class ProductRelationFactory extends Factory
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
            'related_id' => Product::factory(),
            'type' => RelationType::Accessory,
            'sort' => 0,
        ];
    }
}
