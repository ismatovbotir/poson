<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Cart\CartLineKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One piece of a new product in a new cart; line_key follows the product
 * (and roll type, see roll()).
 *
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'roll_type_id' => null,
            'line_key' => fn (array $attributes): string => CartLineKey::make((int) $attributes['product_id'], $attributes['roll_type_id'] ?? null),
            'qty' => 1,
        ];
    }

    /**
     * A label product line in the given roll type (qty = rolls).
     */
    public function roll(RollType $rollType): static
    {
        return $this->state(fn (array $attributes) => [
            'roll_type_id' => $rollType->id,
        ]);
    }
}
