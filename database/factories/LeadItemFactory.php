<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An item of a (new) lead; forProduct() links a product and copies its
 * SKU and uz name as the snapshots.
 *
 * @extends Factory<LeadItem>
 */
class LeadItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'product_id' => null,
            'sku' => null,
            'product_name' => ucfirst(fake()->words(3, true)),
            'qty' => fake()->numberBetween(1, 10),
            'note' => null,
            'sort' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->getTranslation('name', 'uz'),
        ]);
    }
}
