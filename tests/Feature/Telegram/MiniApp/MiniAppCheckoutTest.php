<?php

use App\Actions\Orders\PlaceOrder;
use App\Data\Orders\OrderOrigin;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Events\Orders\OrderPlaced;
use App\Http\Controllers\Tma\CheckoutController;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Cart\CartPricer;
use App\Support\ForeignPhoneLimit;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\OrderMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M4: the Mini App checkout and PlaceOrder with an origin (MA-8, MA-9, MA-10, MA-12, MA-21).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    config(['site.cart_enabled' => true]);
    Event::fake([OrderPlaced::class]);
    $this->tgUser = tmaUser(['name' => 'Aziz Karimov']);
    tmaSignIn($this->tgUser);
});

function tmaCartWith(TelegramUser $user, ?Product $product = null): Cart
{
    $cart = TmaCart::for($user, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => ($product ?? Product::factory()->withIkpu()->create(['price' => 1_000_000_00]))->id, 'qty' => 1]);

    return $cart;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tmaCheckout(Cart $cart, array $overrides = []): TestResponse
{
    $priced = app(CartPricer::class)->price($cart->fresh());

    return test()->post('/tma/uz/checkout', array_merge([
        'cart_fingerprint' => $priced->fingerprint,
        'delivery_fingerprint' => CheckoutController::deliveryFingerprint(app(CheckoutSettings::class)),
        'name' => 'Aziz Karimov',
        'buyer_type' => 'individual',
        'delivery_type' => 'pickup',
        'payment_method' => 'on_receipt',
    ], $overrides));
}

it('shows the checkout prefilled from the registration with the Kokand and region copy', function () {
    tmaCartWith($this->tgUser);
    $this->tgUser->forceFill(['declared_company' => 'Mega Savdo MChJ', 'declared_inn' => '301234567'])->save();

    $html = $this->get('/tma/uz/checkout')->assertOk()->getContent();

    expect($html)->toContain('value="Aziz Karimov"')
        ->toContain('value="Mega Savdo MChJ"')
        ->toContain('value="301234567"')
        ->toContain(__('tma.checkout.delivery_note.courier_kokand', [], 'uz'))
        ->toContain(__('tma.checkout.delivery_note.courier_region', [], 'uz'))
        ->toContain('data-pay-for=')
        ->not->toContain('uzqr_dynamic')
        ->not->toContain('name="phone"');
    expectNoBannedWording($html);
});

it('places a Telegram order with the registration phone, whatever the form says (MA-9)', function () {
    $cart = tmaCartWith($this->tgUser);

    tmaCheckout($cart, ['phone' => '+998911111111'])->assertRedirect();

    $order = Order::query()->sole();
    expect($order->source)->toBe(OrderSource::Telegram)
        ->and($order->telegram_user_id)->toBe($this->tgUser->id)
        ->and($order->phone)->toBe($this->tgUser->phone)
        ->and($order->payment_method)->toBe(PaymentMethod::OnReceipt)
        ->and($cart->fresh()->converted_order_id)->toBe($order->id);
    Event::assertDispatched(OrderPlaced::class);
    $this->get(route('tma.orders.show', ['locale' => 'uz', 'order' => $order->id]))->assertOk();
});

it('answers a repeated tap with the same order (idempotent)', function () {
    $cart = tmaCartWith($this->tgUser);
    $priced = app(CartPricer::class)->price($cart->fresh());

    tmaCheckout($cart);
    $this->post('/tma/uz/checkout', ['cart_fingerprint' => $priced->fingerprint, 'delivery_fingerprint' => CheckoutController::deliveryFingerprint(app(CheckoutSettings::class)), 'name' => 'Aziz Karimov', 'buyer_type' => 'individual', 'delivery_type' => 'pickup', 'payment_method' => 'on_receipt'])
        ->assertRedirect(route('tma.orders.show', ['locale' => 'uz', 'order' => Order::query()->sole()->id]));

    expect(Order::query()->count())->toBe(1);
});

it('refuses online, reserved and unlisted methods (MA-8)', function (string $method, array $overrides) {
    sellerRequisites();
    tmaCheckout(tmaCartWith($this->tgUser), ['payment_method' => $method, ...$overrides])->assertSessionHasErrors('paymentMethod');

    expect(Order::query()->count())->toBe(0);
})->with([
    'payme' => ['payme', []],
    'click' => ['click', []],
    'uzqr_dynamic' => ['uzqr_dynamic', []],
    'uzqr_static not listed' => ['uzqr_static', []],
    'bank transfer for an individual' => ['bank_transfer', []],
    'on receipt for a region' => ['on_receipt', ['delivery_type' => 'courier_region', 'region' => 'samarkand', 'address' => 'Registon 1']],
]);

it('refuses uzqr_dynamic for source Telegram even when the matrix and config would allow it (MA-8)', function () {
    $this->partialMock(PaymentOptions::class, fn ($mock) => $mock->shouldReceive('for')->andReturn([PaymentMethod::UzqrDynamic, PaymentMethod::OnReceipt]));
    config(['telegram.mini_app.payment_methods' => ['on_receipt', 'uzqr_dynamic', 'payme']]);
    $cart = tmaCartWith($this->tgUser);

    try {
        app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['paymentMethod' => 'uzqr_dynamic']), OrderOrigin::telegram($this->tgUser));
        $this->fail('uzqr_dynamic was accepted');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('paymentMethod')
            ->and($exception->errors()['paymentMethod'])->toBe([__('checkout.errors.payment_unavailable')]);
    }

    expect(app(PaymentOptions::class)->forMiniApp(new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 100, true)))
        ->toBe([PaymentMethod::OnReceipt]);
});

it('refuses a method missing from the Mini App config (MA-8)', function () {
    sellerRequisites();
    config(['telegram.mini_app.payment_methods' => ['on_receipt', 'not-a-method']]);

    tmaCheckout(tmaCartWith($this->tgUser), ['buyer_type' => 'company', 'company_name' => 'Mega Savdo MChJ', 'tin' => '301234567', 'payment_method' => 'bank_transfer'])
        ->assertSessionHasErrors('paymentMethod');
});

it('places a bank transfer for a company', function () {
    sellerRequisites();

    tmaCheckout(tmaCartWith($this->tgUser), ['buyer_type' => 'company', 'company_name' => 'Mega Savdo MChJ', 'tin' => '301234567', 'payment_method' => 'bank_transfer'])
        ->assertSessionHasNoErrors();

    expect(Order::query()->sole()->payment_method)->toBe(PaymentMethod::BankTransfer);
});

it('offers a request instead for an individual with a regional courier', function () {
    $cart = tmaCartWith($this->tgUser);

    $html = $this->get('/tma/uz/checkout')->getContent();
    preg_match_all('/data-pay-for="([^"]*)"/', $html, $matches);

    expect(implode(' ', $matches[1]))->not->toContain('individual:courier_region')
        ->and($html)->toContain('data-no-method')->toContain(route('tma.cart.quote', ['locale' => 'uz']));

    tmaCheckout($cart, ['delivery_type' => 'courier_region', 'region' => 'samarkand', 'address' => 'Registon 1', 'payment_method' => ''])->assertSessionHasErrors();
    expect(Order::query()->count())->toBe(0);
});

it('sends an anonymous or a stale phone to the manager screen (MA-9)', function (Closure $setup) {
    $setup($this->tgUser);
    $cart = tmaCartWith($this->tgUser);

    $this->get('/tma/uz/checkout')->assertRedirect(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'phone']));
    tmaCheckout($cart)->assertRedirect(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'phone']));
    $this->post('/tma/uz/cart/quote')->assertRedirect(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'phone']));

    expect(Order::query()->count())->toBe(0)
        ->and(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart), OrderOrigin::telegram($this->tgUser->fresh())))->toThrow(ValidationException::class);
})->with([
    '+888' => [fn (TelegramUser $user) => $user->forceFill(['phone' => '+88800001234567'])->save()],
    'newer registration of the phone' => [fn (TelegramUser $user) => TelegramUser::factory()->withPhone($user->phone, now())->create()],
]);

it('accepts a registered foreign phone unless foreign phones are switched off', function () {
    $this->tgUser->forceFill(['phone' => '+77011234567'])->save();

    tmaCheckout(tmaCartWith($this->tgUser))->assertSessionHasNoErrors();
    expect(Order::query()->sole()->phone)->toBe('+77011234567');

    config(['site.foreign_phones_enabled' => false]);
    $other = tmaUser(['phone' => '+77019999999']);
    tmaSignIn($other);
    tmaCheckout(tmaCartWith($other))->assertSessionHasErrors();
});

it('links the customer only through a verified link (MA-9)', function () {
    $customer = Customer::factory()->create();
    $this->tgUser->forceFill(['customer_id' => $customer->id])->save();

    tmaCheckout(tmaCartWith($this->tgUser));
    expect(Order::query()->latest('id')->first()->customer_id)->toBeNull();

    $this->tgUser->forceFill(['link_verified_at' => now()])->save();
    tmaCheckout(tmaCartWith($this->tgUser));
    expect(Order::query()->latest('id')->first()->customer_id)->toBe($customer->id);
});

it('checks the cart owner inside PlaceOrder, both directions (MA-21)', function () {
    $tmaCart = tmaCartWith($this->tgUser);
    $other = tmaUser(['phone' => '+998907654321']);
    $siteCart = orderCart();

    expect(fn () => app(PlaceOrder::class)->handle($tmaCart->token, checkoutData($tmaCart)))->toThrow(ValidationException::class)
        ->and(fn () => app(PlaceOrder::class)->handle($tmaCart->token, checkoutData($tmaCart), OrderOrigin::telegram($other)))->toThrow(ValidationException::class)
        ->and(fn () => app(PlaceOrder::class)->handle($siteCart->token, checkoutData($siteCart), OrderOrigin::telegram($this->tgUser)))->toThrow(ValidationException::class)
        ->and(Order::query()->count())->toBe(0);

    // The site checkout itself is unchanged.
    expect(app(PlaceOrder::class)->handle($siteCart->token, checkoutData($siteCart))->source)->toBe(OrderSource::Site);
});

it('refuses a changed price (fingerprint)', function () {
    tmaCheckout(tmaCartWith($this->tgUser), ['cart_fingerprint' => 'stale'])->assertSessionHasErrors('fingerprint');

    expect(Order::query()->count())->toBe(0);
});

it('stops a user over the per-user order budget (MA-12)', function () {
    foreach (range(1, CheckoutController::PER_USER) as $hit) {
        RateLimiter::hit('tma-checkout:'.$this->tgUser->id, 600);
    }

    tmaCheckout(tmaCartWith($this->tgUser))->assertSessionHasErrors('form');
    expect(Order::query()->count())->toBe(0);
});

it('counts a double submit once in every budget', function () {
    $this->tgUser->forceFill(['phone' => '+77011234567'])->save();
    $cart = tmaCartWith($this->tgUser);
    $foreign = fn (): int => RateLimiter::attempts(ForeignPhoneLimit::KEY);
    $before = $foreign();

    tmaCheckout($cart)->assertSessionHasNoErrors();
    tmaCheckout($cart)->assertSessionHasNoErrors();

    expect(Order::query()->count())->toBe(1)
        ->and(RateLimiter::attempts('tma-checkout:'.$this->tgUser->id))->toBe(1)
        ->and(RateLimiter::attempts('checkout-phone:'.sha1('+77011234567')))->toBe(1)
        ->and($foreign() - $before)->toBe(1);
});

it('marks Mini App orders in the sales chat message', function () {
    $order = Order::factory()->telegram($this->tgUser)->create();
    $site = Order::factory()->create();

    expect(OrderMessage::placed($order, 'uz')->html)->toContain('Telegram Mini App')
        ->and(OrderMessage::placed($site, 'uz')->html)->not->toContain('Telegram Mini App')
        ->and(OrderMessage::placed($order, 'uz')->html)->not->toContain((string) $this->tgUser->telegram_id)
        ->not->toContain($order->ulid);
});

it('copies every checkout field when only the phone changes', function () {
    $data = checkoutData(orderCart(), ['comment' => 'Kech soat 5 da', 'attribution' => ['source' => 'telegram'], 'trafficChannel' => 'social']);
    $copy = $data->withPhone('+998901112233');

    expect($copy->phone)->toBe('+998901112233')
        ->and(array_diff_key(get_object_vars($copy), ['phone' => true]))->toBe(array_diff_key(get_object_vars($data), ['phone' => true]));
});
