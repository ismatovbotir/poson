<?php

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\RemoveCartLine;
use App\Actions\Cart\UpdateCartLine;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Cart\CartToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['site.cart_enabled' => true]);
    $this->token = CartToken::generate();
});

/**
 * Add through the action with the test's token.
 */
function addToCart(Product|int $product, int $qty = 1, ?int $rollTypeId = null, ?string $token = null): CartItem
{
    return app(AddToCart::class)->handle($token ?? test()->token, $product instanceof Product ? $product->id : $product, $rollTypeId, $qty, 'ru');
}

it('creates the cart lazily on the first add, with one line keyed by the product', function () {
    $product = Product::factory()->create(['min_qty' => 1]);

    expect(Cart::query()->count())->toBe(0);

    $item = addToCart($product, 2);

    $cart = Cart::query()->sole();

    expect($cart->token)->toBe($this->token)
        ->and($cart->locale)->toBe('ru')
        ->and($item->cart_id)->toBe($cart->id)
        ->and($item->line_key)->toBe('p'.$product->id)
        ->and($item->roll_type_id)->toBeNull()
        ->and($item->qty)->toBe(2);
});

it('adds to the existing line instead of creating a duplicate row (also without a roll type)', function () {
    $product = Product::factory()->create();

    addToCart($product, 2);
    addToCart($product, 3);

    expect(CartItem::query()->count())->toBe(1)
        ->and(CartItem::query()->sole()->qty)->toBe(5);
});

it('enforces one row per line key in the database', function () {
    $item = CartItem::factory()->create();

    expect(fn () => CartItem::query()->create([
        'cart_id' => $item->cart_id,
        'line_key' => $item->line_key,
        'product_id' => $item->product_id,
        'roll_type_id' => null,
        'qty' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects products and quantities the cart does not accept, creating nothing', function (Closure $setup) {
    [$productId, $qty, $rollTypeId] = $setup();

    expect(fn () => addToCart($productId, $qty, $rollTypeId))->toThrow(ValidationException::class);

    expect(CartItem::query()->count())->toBe(0);
})->with([
    'inactive product' => [fn () => [Product::factory()->inactive()->create()->id, 1, null]],
    'product in a hidden category' => [fn () => [Product::factory()->inCategory(Category::factory()->create(['is_active' => false]))->create()->id, 1, null]],
    'deleted product' => [fn () => [tap(Product::factory()->create())->delete()->id, 1, null]],
    'unknown product' => [fn () => [999_999, 1, null]],
    'quote-only product' => [fn () => [Product::factory()->quoteOnly()->create()->id, 1, null]],
    'no Retail price' => [fn () => [Product::factory()->create(['price' => null, 'price_dealer' => 100_000, 'sale_mode' => SaleMode::Both])->id, 1, null]],
    'out of stock' => [fn () => [Product::factory()->create(['stock_status' => StockStatus::OutOfStock])->id, 1, null]],
    'below min_qty' => [fn () => [Product::factory()->create(['min_qty' => 5])->id, 4, null]],
    'zero quantity' => [fn () => [Product::factory()->create()->id, 0, null]],
    'negative quantity' => [fn () => [Product::factory()->create()->id, -3, null]],
    'more than 10 000' => [fn () => [Product::factory()->create()->id, Cart::MAX_QTY + 1, null]],
    'roll type on a non-label product' => [fn () => [Product::factory()->create()->id, 1, RollType::factory()->create()->id]],
    'label product without a roll type' => [fn () => [Product::factory()->perLabel()->withRollTypes()->create()->id, 2, null]],
    'roll type not attached to the label product' => [fn () => [Product::factory()->perLabel()->withRollTypes()->create()->id, 2, RollType::factory()->create()->id]],
    'inactive roll type' => [function () {
        $rollType = RollType::factory()->inactive()->create();

        return [Product::factory()->perLabel()->withRollTypes([$rollType])->create()->id, 2, $rollType->id];
    }],
    'unknown roll type id' => [fn () => [Product::factory()->perLabel()->withRollTypes()->create()->id, 2, 999_999]],
]);

it('rejects an add that would take the line past 10 000', function () {
    $product = Product::factory()->create();
    addToCart($product, Cart::MAX_QTY);

    expect(fn () => addToCart($product, 1))->toThrow(ValidationException::class);
    expect(CartItem::query()->sole()->qty)->toBe(Cart::MAX_QTY);
});

it('holds at most 50 lines', function () {
    $cart = Cart::factory()->create(['token' => $this->token]);
    CartItem::factory()->count(Cart::MAX_LINES)->for($cart)->create();

    expect(fn () => addToCart(Product::factory()->create()))->toThrow(ValidationException::class, __('checkout.cart.errors.too_many_lines', ['max' => Cart::MAX_LINES]));
    expect($cart->items()->count())->toBe(Cart::MAX_LINES);
});

it('adds a label product in an attached, active roll type as its own line', function () {
    [$small, $large] = [RollType::factory()->size(500, '40.0')->create(), RollType::factory()->size(1000, '40.0')->create()];
    $product = Product::factory()->perLabel()->withRollTypes([$small, $large])->create(['min_qty' => 2]);

    addToCart($product, 2, $small->id);
    addToCart($product, 3, $large->id);

    expect(CartItem::query()->orderBy('id')->pluck('line_key')->all())->toBe(['p'.$product->id.'-r'.$small->id, 'p'.$product->id.'-r'.$large->id]);
});

it('refuses everything while the cart is off', function () {
    config(['site.cart_enabled' => false]);

    expect(fn () => addToCart(Product::factory()->create()))->toThrow(ValidationException::class, __('checkout.cart.errors.disabled'));
    expect(Cart::query()->count())->toBe(0);
});

it('refuses a malformed token', function () {
    expect(fn () => addToCart(Product::factory()->create(), token: 'not-a-ulid'))->toThrow(ValidationException::class);
});

it('updates and removes lines by key only, rejecting tampered keys', function (string $lineKey) {
    $product = Product::factory()->create();
    addToCart($product, 2);
    $cart = Cart::query()->sole();

    expect(fn () => app(UpdateCartLine::class)->handle($cart, $lineKey, 3))->toThrow(ValidationException::class);
    expect(fn () => app(RemoveCartLine::class)->handle($cart, $lineKey))->toThrow(ValidationException::class);
    expect(CartItem::query()->sole()->qty)->toBe(2);
})->with([
    'empty' => [''],
    'id only' => ['12'],
    'sql' => ["p1' or 1=1 --"],
    'leading zero' => ['p01'],
    'too long id' => ['p12345678901'],
    'roll without product' => ['-r3'],
    'uppercase' => ['P1'],
    'trailing text' => ['p1-r2x'],
]);

it('changes the quantity within min_qty..10 000 and removes a line', function () {
    $product = Product::factory()->create(['min_qty' => 2]);
    addToCart($product, 2);
    $cart = Cart::query()->sole();
    $key = 'p'.$product->id;

    app(UpdateCartLine::class)->handle($cart, $key, 7);
    expect(CartItem::query()->sole()->qty)->toBe(7);

    expect(fn () => app(UpdateCartLine::class)->handle($cart, $key, 1))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateCartLine::class)->handle($cart, $key, Cart::MAX_QTY + 1))->toThrow(ValidationException::class);

    app(RemoveCartLine::class)->handle($cart, $key);
    expect(CartItem::query()->count())->toBe(0);
});

it('lets an unavailable line only be removed, not changed', function () {
    $product = Product::factory()->create();
    addToCart($product, 2);
    $product->update(['stock_status' => StockStatus::OutOfStock]);
    $cart = Cart::query()->sole();

    expect(fn () => app(UpdateCartLine::class)->handle($cart, 'p'.$product->id, 3))->toThrow(ValidationException::class, __('checkout.cart.errors.unavailable'));

    app(RemoveCartLine::class)->handle($cart, 'p'.$product->id);
    expect(CartItem::query()->count())->toBe(0);
});

it('can not change a cart an order was placed from', function () {
    $product = Product::factory()->create();
    addToCart($product);
    $cart = tap(Cart::query()->sole())->update(['converted_at' => now()]);

    expect(fn () => addToCart($product))->toThrow(ValidationException::class, __('checkout.cart.errors.closed'))
        ->and(fn () => app(UpdateCartLine::class)->handle($cart, 'p'.$product->id, 2))->toThrow(ValidationException::class)
        ->and(fn () => app(RemoveCartLine::class)->handle($cart, 'p'.$product->id))->toThrow(ValidationException::class);
});
