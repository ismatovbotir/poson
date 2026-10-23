<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A free-text line of a (new) quote; forProduct() snapshots a product.
 *
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 5);
        $price = fake()->numberBetween(100, 5000) * 1000 * 100;

        return [
            'quote_id' => Quote::factory(),
            'product_id' => null,
            'name' => ucfirst(fake()->words(3, true)),
            'sku' => null,
            'unit' => null,
            'qty' => $qty,
            'price' => $price,
            'total' => $price * $qty,
            'sort' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'name' => $product->getTranslation('name', 'uz'),
            'sku' => $product->sku,
            'unit' => $product->unit,
            'price' => (int) $product->price,
            'total' => (int) $product->price * $attributes['qty'],
        ]);
    }
}
