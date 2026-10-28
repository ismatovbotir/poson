<?php

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A public site cart (plan 11 v2 §1.1). Anonymous: identified only by the
 * opaque ULID token of the encrypted "poson_cart" cookie
 * (App\Support\Cart\CartToken); no user, no personal data, no prices.
 * Created lazily by App\Actions\Cart\AddToCart; every write goes through
 * the cart actions under a row lock. Lines are priced on every request by
 * App\Support\Cart\CartPricer (Retail only).
 *
 * converted_order_id / converted_at: set once an order is placed from the
 * cart (plan 11b); such a cart is closed for changes.
 *
 * @property int $id
 * @property string $token
 * @property string $locale
 * @property int|null $converted_order_id
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token', 'locale', 'converted_order_id', 'converted_at'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /**
     * Most lines in one cart.
     */
    public const MAX_LINES = 50;

    /**
     * Most pieces (or rolls) in one line.
     */
    public const MAX_QTY = 10_000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'converted_order_id' => 'integer',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /**
     * Carts still open for changes (no order placed from them).
     *
     * @param  Builder<Cart>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('converted_at');
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }
}
