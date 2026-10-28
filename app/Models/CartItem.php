<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a cart: a product, or a label product in one of its roll
 * types (qty = rolls). line_key ("p12" / "p12-r3",
 * App\Support\Cart\CartLineKey) is unique per cart. No prices are stored:
 * App\Support\Cart\CartPricer prices the line from the product each time.
 * Saving a line touches its cart (pruning reads carts.updated_at).
 *
 * @property int $id
 * @property int $cart_id
 * @property string $line_key
 * @property int $product_id
 * @property int|null $roll_type_id
 * @property int $qty
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['cart_id', 'line_key', 'product_id', 'roll_type_id', 'qty'])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $touches = ['cart'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cart_id' => 'integer',
            'product_id' => 'integer',
            'roll_type_id' => 'integer',
            'qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<RollType, $this>
     */
    public function rollType(): BelongsTo
    {
        return $this->belongsTo(RollType::class);
    }
}
