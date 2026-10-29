<?php

namespace Database\Factories;

use App\Enums\ProductUnit;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A free-text line of a (new) order with an IKPU; forProduct() snapshots a
 * product at its Retail price, rollLine() a label product in a roll type.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'order_id' => Order::factory(),
            'product_id' => null,
            'name' => ucfirst(fake()->words(3, true)),
            'sku' => null,
            'unit' => ProductUnit::Piece,
            'qty' => $qty,
            'price' => $price,
            'total' => $price * $qty,
            'ikpu_code' => '08471001001000000',
            'package_code' => '1501171',
            'vat_percent' => 12,
            'sort' => 0,
        ];
    }

    public function forProduct(Product $product, int $qty = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'name' => $product->getTranslation('name', 'uz'),
            'sku' => $product->sku,
            'unit' => $product->unit,
            'qty' => $qty,
            'price' => (int) $product->price,
            'total' => (int) $product->price * $qty,
            'ikpu_code' => $product->ikpu_code,
            'package_code' => $product->package_code,
        ]);
    }

    public function withoutIkpu(): static
    {
        return $this->state(fn (array $attributes) => ['ikpu_code' => null, 'package_code' => null]);
    }

    /**
     * A label line: rolls × per-roll price (per-label price × labels per roll).
     */
    public function rollLine(RollType $rollType, int $rolls = 2, int $pricePerLabel = 1200): static
    {
        return $this->state(fn (array $attributes) => [
            'unit' => ProductUnit::Roll,
            'qty' => $rolls,
            'roll_type_id' => $rollType->id,
            'labels_per_roll' => $rollType->labels_per_roll,
            'core_diameter_mm' => $rollType->core_diameter_mm,
            'label_size' => '58×40',
            'price_per_label' => $pricePerLabel,
            'price' => RollPricing::rollPrice($pricePerLabel, $rollType->labels_per_roll),
            'total' => RollPricing::rollPrice($pricePerLabel, $rollType->labels_per_roll) * $rolls,
        ]);
    }
}
