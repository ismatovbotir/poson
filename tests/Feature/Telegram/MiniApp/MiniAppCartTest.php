<?php

use App\Actions\Cart\AddToCart;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Events\LeadCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Lead;
use App\Models\Product;
use App\Support\Cart\CartToken;
use App\Support\Money;
use App\Support\Telegram\MiniApp\TmaCart;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M3: the Mini App cart (MA-10, MA-21).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    config(['site.cart_enabled' => true]);
    $this->tgUser = tmaUser();
    tmaSignIn($this->tgUser);
});

it('adds to the user own cart and counts lines in the header', function () {
    $product = Product::factory()->create();

    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 2])
        ->assertRedirect(route('tma.product', ['locale' => 'uz', 'product' => $product->slug]))
        ->assertSessionHas('tma_status', 'added');

    $cart = Cart::query()->sole();

    expect($cart->telegram_user_id)->toBe($this->tgUser->id)
        ->and($cart->items()->sole()->qty)->toBe(2)
        ->and($this->get('/tma/uz')->getContent())->toContain('data-cart-count="1"');
});

it('keeps one open cart per user, also when created twice (MA-10)', function () {
    $first = TmaCart::for($this->tgUser, 'uz');
    $second = TmaCart::for($this->tgUser, 'uz');

    expect($second->is($first))->toBeTrue()
        ->and(Cart::query()->where('telegram_user_id', $this->tgUser->id)->count())->toBe(1);
});

it('refuses what AddToCart refuses', function (Closure $make, array $input) {
    $product = $make();

    $response = $this->post('/tma/uz/cart', ['product_id' => $product->id, ...$input]);

    $response->assertSessionHasErrors();
    expect(CartItem::query()->count())->toBe(0);
})->with([
    'quote only' => [fn () => Product::factory()->quoteOnly()->create(), ['qty' => 1]],
    'price on request' => [fn () => Product::factory()->priceOnRequest()->create(), ['qty' => 1]],
    'out of stock' => [fn () => Product::factory()->create(['stock_status' => 'out_of_stock']), ['qty' => 1]],
    'below min_qty' => [fn () => Product::factory()->create(['min_qty' => 5]), ['qty' => 2]],
    'zero qty' => [fn () => Product::factory()->create(), ['qty' => 0]],
    'not a number' => [fn () => Product::factory()->create(), ['qty' => 'abc']],
    'label product without roll' => [fn () => Product::factory()->perLabel()->withRollTypes()->create(), ['qty' => 2]],
    'roll type of another product' => [fn () => Product::factory()->perLabel()->withRollTypes()->create(), ['qty' => 2, 'roll_type_id' => 999999]],
]);

it('404s a product that is not on the site', function () {
    $this->post('/tma/uz/cart', ['product_id' => Product::factory()->inactive()->create()->id, 'qty' => 1])->assertNotFound();
});

it('adds a label product in a roll type with min_qty rolls', function () {
    $product = Product::factory()->perLabel()->withRollTypes()->create();
    $rollType = $product->rollTypes()->first();

    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'roll_type_id' => $rollType->id, 'qty' => 2])->assertSessionHasNoErrors();

    expect(CartItem::query()->sole()->roll_type_id)->toBe($rollType->id);
});

it('never takes a price from the request', function () {
    $product = Product::factory()->create(['price' => 500_000_00]);

    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1, 'price' => 1, 'total' => 1]);

    $this->get('/tma/uz/cart')->assertOk()->assertSee(Money::format(500_000_00));
});

it('updates and removes lines of the own cart only (MA-10)', function () {
    $product = Product::factory()->create();
    $own = TmaCart::for($this->tgUser, 'uz');
    CartItem::factory()->for($own)->create(['product_id' => $product->id, 'qty' => 1]);

    $other = Cart::factory()->forTelegramUser(tmaUser(['phone' => '+998907654321']))->create();
    $otherItem = CartItem::factory()->for($other)->create(['product_id' => $product->id, 'qty' => 1]);
    $site = Cart::factory()->create();
    $siteItem = CartItem::factory()->for($site)->create(['product_id' => $product->id, 'qty' => 1]);

    $key = 'p'.$product->id;
    $this->post('/tma/uz/cart/'.$key, ['qty' => 3])->assertRedirect(route('tma.cart', ['locale' => 'uz']));
    $this->post('/tma/uz/cart/'.$key.'/remove')->assertRedirect(route('tma.cart', ['locale' => 'uz']));

    expect($own->items()->count())->toBe(0)
        ->and($otherItem->fresh()->qty)->toBe(1)
        ->and($siteItem->fresh()->qty)->toBe(1);
});

it('keeps Mini App and site carts apart (MA-21)', function () {
    $product = Product::factory()->create();
    $tmaCart = TmaCart::for($this->tgUser, 'uz');

    // A site token action never uses a Mini App cart…
    expect(fn () => app(AddToCart::class)->handle($tmaCart->token, $product->id, null, 1, 'uz'))->toThrow(ValidationException::class);

    // …the cookie never finds it…
    app(CartToken::class)->issue($tmaCart->token);
    expect(app(CartToken::class)->cart())->toBeNull();

    // …and a Mini App action never uses a site cart or creates one by token.
    $siteCart = Cart::factory()->create();
    expect(fn () => app(AddToCart::class)->handle($siteCart->token, $product->id, null, 1, 'uz', $this->tgUser->id))->toThrow(ValidationException::class)
        ->and(fn () => app(AddToCart::class)->handle(CartToken::generate(), $product->id, null, 1, 'uz', $this->tgUser->id))->toThrow(ValidationException::class);
});

it('never renders the cart token', function () {
    $product = Product::factory()->create();
    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1]);
    $token = Cart::query()->sole()->token;

    foreach (['/tma/uz/cart', '/tma/uz/checkout', '/tma/uz/p/'.$product->slug] as $url) {
        expect($this->get($url)->getContent())->not->toContain($token)->not->toContain(strtolower($token));
    }
});

it('shows the cart with a Checkout main button and flags lines that cannot be bought', function () {
    $ok = Product::factory()->create();
    $gone = Product::factory()->create();
    $cart = TmaCart::for($this->tgUser, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => $ok->id, 'qty' => 1]);

    $this->get('/tma/uz/cart')->assertOk()->assertSee('data-main-button', false)->assertSee('data-checkout-link', false);

    CartItem::factory()->for($cart)->create(['product_id' => $gone->id, 'qty' => 1]);
    $gone->update(['is_active' => false]);

    $this->get('/tma/uz/cart')->assertOk()->assertSee('data-invalid', false)->assertSee('data-cart-blocked', false)->assertDontSee('data-checkout-link', false);
});

it('sends the cart as a quote request with the registration name and phone, source Telegram', function () {
    Event::fake([LeadCreated::class]);
    $product = Product::factory()->create();
    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => $product->id, 'qty' => 2]);

    $this->post('/tma/uz/cart/quote', ['comment' => 'Tezroq', 'phone' => '+998911111111', 'name' => 'Hacker'])
        ->assertSessionHas('tma_status', 'quoted');

    $lead = Lead::query()->sole();
    expect($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe(LeadType::Checkout)
        ->and($lead->phone)->toBe($this->tgUser->phone)
        ->and($lead->name)->toBe($this->tgUser->name);
});
