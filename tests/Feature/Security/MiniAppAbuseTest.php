<?php

use App\Actions\Cart\AddToCart;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PriceLevel;
use App\Events\LeadCreated;
use App\Events\Orders\OrderPlaced;
use App\Http\Controllers\Tma\CheckoutController;
use App\Http\Middleware\ConfigureTmaSession;
use App\Livewire\Site\Cart\Counter;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use App\Support\ForeignPhoneLimit;
use App\Support\HtmlSanitizer;
use App\Support\Orders\CheckoutSettings;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaLinks;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/MiniApp.php';

/*
| Plan 20 (Telegram Mini App, M1-M4) abuse cases. In-process, test DB only,
| initData signed with the fake test token (tests/Helpers/MiniApp.php).
|
| Already covered elsewhere, not repeated here:
| - 1  a refused sign-in of B wipes A's session (unregistered, banned, blocked; also unknown, forged, switch off):
|      MiniAppSecurityFixesTest "ends the previous account session on every refused sign-in (M1)"
| - 2  forged initData posted with A's session: the "forged initData" row of the same dataset
| - 6  Telegram order in admin (no free line, posted price ignored, new lines Retail): MiniAppAdminOrderTest
| - 7  region fee changed between show and store gives a fingerprint error: MiniAppSecurityFixesTest (L1)
| - 8  uzqr_dynamic refusal on the paymentMethod key: MiniAppCheckoutTest "refuses uzqr_dynamic for source Telegram ..."
| - 16 X-Frame-Options DENY on site 419 / binding 404: MiniAppSecurityFixesTest (L3)
| - 17 Redactor masks JSON init_data: Monitoring\RedactorTest; dontFlash: MiniAppSecurityFixesTest (L6) and MiniAppAuthTest
| - 19 failed checkout attempts capped at 20 per user: MiniAppSecurityFixesTest (L2)
| Partly covered (only the missing part is tested below): 4, 5, 9, 11, 18, 20, 21.
*/
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    tmaEnabled();
    config(['site.cart_enabled' => true]);
});

function abuseCartWith(TelegramUser $user, ?Product $product = null): Cart
{
    $cart = TmaCart::for($user, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => ($product ?? Product::factory()->withIkpu()->create(['price' => 1_000_000_00]))->id, 'qty' => 1]);

    return $cart;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function abuseCheckout(Cart $cart, array $overrides = []): TestResponse
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

// ---------------------------------------------------------------- 3

it('accepts a video watch URL only on an allowed https host without credentials', function (string $url, bool $accepted) {
    expect(TmaLinks::video($url))->toBe($accepted ? $url : null);
})->with([
    'https youtube' => ['https://youtube.com/watch?v=x', true],
    'https www.youtube' => ['https://www.youtube.com/watch?v=x', true],
    'http' => ['http://youtube.com/watch?v=x', false],
    'lookalike subdomain of evil' => ['https://youtube.com.evil.tld/watch?v=x', false],
    'credentials' => ['https://u:p@youtube.com/watch?v=x', false],
    'user only' => ['https://u@youtube.com/watch?v=x', false],
    'protocol relative' => ['//youtube.com/watch?v=x', false],
    'javascript' => ['javascript:alert(1)', false],
    'javascript with host text' => ['javascript://youtube.com/%0Aalert(1)', false],
    'host not on the list' => ['https://evil.tld/watch?v=x', false],
]);

// ---------------------------------------------------------------- 4

it('drops the href of unsafe description links and resolves a relative one on APP_URL', function (string $href, ?string $expected) {
    $html = TmaLinks::description('<p><a id="t" href="'.$href.'">link</a></p>');

    if ($expected === null) {
        expect($html)->not->toContain('href=')->not->toContain('data-open-link')->not->toContain($href);

        return;
    }

    expect($html)->toContain('href="'.$expected.'"')->toContain('data-open-link="'.$expected.'"')
        ->toContain('rel="noopener noreferrer nofollow"');
})->with([
    'javascript' => ['javascript:alert(1)', null],
    'JavaScript mixed case' => ['JaVaScRiPt:alert(1)', null],
    'data' => ['data:text/html;base64,PHNjcmlwdD4=', null],
    'protocol relative' => ['//evil.example/x', null],
    'credentials' => ['https://user:pass@vendor.example/x', null],
    'plain http' => ['http://vendor.example/x', null],
    'relative site path' => ['/uz/page/delivery', 'https://poson.example.uz/uz/page/delivery'],
]);

it('strips onclick and style from the description the product page renders', function () {
    $tmaUser = tmaUser();
    tmaSignIn($tmaUser);
    $product = Product::factory()->create(['description' => [
        'uz' => '<p style="color:red" onclick="steal()">Matn <a href="/uz/page/delivery" onclick="steal()" style="position:fixed">havola</a> <a href="javascript:steal()">xavfli</a></p>',
        'ru' => '', 'en' => '',
    ]]);

    $html = $this->get('/tma/uz/p/'.$product->slug)->assertOk()->getContent();

    expect($html)->not->toContain('onclick')->not->toContain('style=')->not->toContain('javascript:')
        ->toContain('data-open-link="https://poson.example.uz/uz/page/delivery"');
});

it('strips onclick and style also when the sanitized description goes through TmaLinks', function () {
    $out = TmaLinks::description(HtmlSanitizer::clean('<a href="https://vendor.example/a" onclick="x()" style="top:0" onmouseover="y()">a</a>'));

    expect($out)->not->toContain('onclick')->not->toContain('style')->not->toContain('onmouseover')->toContain('href="https://vendor.example/a"');
});

// ---------------------------------------------------------------- 5

it('shows the invoice link only for a confirmed bank transfer with a valid public link', function (Closure $change, bool $shown) {
    sellerRequisites();
    $user = tmaUser();
    tmaSignIn($user);
    $order = Order::factory()->telegram($user)->bankTransfer()->totalling(1_000_000_00)->create(['status' => OrderStatus::Confirmed]);
    OrderItem::factory()->for($order)->create();
    $change($order);

    $html = $this->get('/tma/uz/orders/'.$order->id)->assertOk()->getContent();

    if ($shown) {
        expect($html)->toContain('data-invoice')->toContain($order->ulid);

        return;
    }

    expect($html)->not->toContain('data-invoice')->not->toContain('invoice.pdf')->not->toContain($order->ulid);
})->with([
    'control: confirmed bank transfer' => [fn () => null, true],
    'link expired' => [fn (Order $order) => $order->update(['public_until' => now()->subSecond()]), false],
    'no public link' => [fn (Order $order) => $order->update(['public_until' => null]), false],
    'new' => [fn (Order $order) => $order->update(['status' => OrderStatus::New]), false],
    'cancelled' => [fn (Order $order) => $order->update(['status' => OrderStatus::Cancelled]), false],
    'on receipt' => [fn (Order $order) => $order->update(['payment_method' => PaymentMethod::OnReceipt]), false],
    'staff switched to uzqr_dynamic' => [fn (Order $order) => $order->update(['payment_method' => PaymentMethod::UzqrDynamic]), false],
    'staff switched to uzqr_static' => [fn (Order $order) => $order->update(['payment_method' => PaymentMethod::UzqrStatic]), false],
]);

// ---------------------------------------------------------------- 9

it('ignores tampered checkout fields', function (string $field, mixed $value) {
    $user = tmaUser(['name' => 'Aziz Karimov']);
    tmaSignIn($user);
    $stranger = Customer::factory()->create();
    $other = tmaUser(['phone' => '+998907654321']);
    $cart = abuseCartWith($user);
    $replacements = ['customer_id' => $stranger->id, 'telegram_user_id' => $other->id];

    abuseCheckout($cart, [$field => $replacements[$field] ?? $value])->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order->phone)->toBe($user->phone)
        ->and($order->telegram_user_id)->toBe($user->id)
        ->and($order->source)->toBe(OrderSource::Telegram)
        ->and($order->customer_id)->not->toBe($stranger->id)
        ->and($order->price_level)->toBe(PriceLevel::Retail)
        ->and($order->total)->toBe(1_000_000_00)
        ->and($order->items()->sole()->price)->toBe(1_000_000_00);
})->with([
    'phone' => ['phone', '+998911111111'],
    'telegram_user_id' => ['telegram_user_id', 0],
    'source' => ['source', 'manual'],
    'customer_id' => ['customer_id', 0],
    'price' => ['price', 1],
    'total' => ['total', 1],
    'subtotal' => ['subtotal', 1],
    'price_level' => ['price_level', 'wholesale'],
]);

// ---------------------------------------------------------------- 10

it('answers an array in a required checkout field with a redirect and errors, never a 500', function (string $field) {
    $user = tmaUser();
    tmaSignIn($user);

    $response = abuseCheckout(abuseCartWith($user), [$field => ['x', ['y']]]);

    expect($response->getStatusCode())->toBeLessThan(500);
    $response->assertRedirect(route('tma.checkout', ['locale' => 'uz']))->assertSessionHasErrors();
    expect(Order::query()->count())->toBe(0);
})->with(['name', 'payment_method', 'buyer_type', 'cart_fingerprint', 'delivery_fingerprint']);

it('treats an array in an optional checkout field as empty, never a 500 and never stored', function (string $field) {
    Event::fake([OrderPlaced::class]);
    $user = tmaUser();
    tmaSignIn($user);

    $response = abuseCheckout(abuseCartWith($user), [$field => ['x', ['y']]]);

    expect($response->getStatusCode())->toBeLessThan(500);
    $response->assertRedirect();
    expect(Order::query()->count())->toBeLessThanOrEqual(1)
        ->and(json_encode(Order::query()->first()?->getAttributes()))->not->toContain('Array');
})->with(['comment', 'delivery_type', 'region', 'address', 'company_name', 'tin', 'kokand_confirmed']);

it('answers an array quantity on cart add and update with a redirect and errors', function (string $route) {
    $user = tmaUser();
    tmaSignIn($user);
    $cart = abuseCartWith($user);
    $product = Product::factory()->create();
    $lineKey = $cart->items()->sole()->line_key;
    $url = $route === 'add' ? '/tma/uz/cart' : '/tma/uz/cart/'.$lineKey;

    $response = $this->post($url, ['product_id' => $product->id, 'qty' => ['1', '2']]);

    expect($response->getStatusCode())->toBeLessThan(500);
    $response->assertRedirect()->assertSessionHasErrors('cart');
})->with(['add', 'update']);

it('answers an array product_id on cart add with a plain 404, never a 500', function () {
    tmaSignIn(tmaUser());

    $this->post('/tma/uz/cart', ['product_id' => ['1'], 'qty' => 1])->assertNotFound();
    $this->post('/tma/uz/cart', ['product_id' => Product::factory()->create()->id, 'roll_type_id' => ['1'], 'qty' => 1])->assertRedirect();
});

it('answers array comments on quote requests with a redirect, never a 500', function () {
    $user = tmaUser();
    tmaSignIn($user);
    $product = Product::factory()->create();
    CartItem::factory()->for(TmaCart::for($user, 'uz'))->create(['product_id' => $product->id, 'qty' => 1]);

    foreach (['/tma/uz/p/'.$product->slug.'/quote', '/tma/uz/cart/quote'] as $url) {
        $response = $this->post($url, ['comment' => ['a', 'b']]);

        expect($response->getStatusCode())->toBeLessThan(500);
        $response->assertRedirect();
    }
});

it('answers array sign-in fields with JSON and a redirect URL, never a 500', function (array $payload) {
    $response = $this->postJson(route('tma.auth'), $payload);

    expect($response->getStatusCode())->toBeLessThan(500);
    $response->assertJsonStructure(['redirect']);
    expect(session(TmaSession::USER))->toBeNull();
})->with([
    'init_data[]' => [['init_data' => ['a', 'b']]],
    'init_data[] with next' => [['init_data' => [['x']], 'next' => 'orders']],
]);

it('treats an array next on sign-in as no next and an array next on the bootstrap page as absent', function () {
    $user = tmaUser();

    $this->postJson(route('tma.auth'), ['init_data' => tmaInitData($user->telegram_id), 'next' => ['orders']])
        ->assertOk()->assertJson(['redirect' => route('tma.home', ['locale' => 'uz'])]);

    $this->get('/tma?next[]=orders')->assertOk()->assertDontSee('data-next=', false);
});

// ---------------------------------------------------------------- 11 + 20

it('sends a hostile or foreign "next" to the home screen or the orders list only', function (Closure $next, string $expectedRoute) {
    $user = tmaUser();
    $target = $next();

    $redirect = tmaAuth(tmaInitData($user->telegram_id), $target)->assertOk()->json('redirect');

    expect($redirect)->toBe(route($expectedRoute, ['locale' => 'uz']))
        ->and($redirect)->not->toContain('evil');
})->with([
    'protocol relative' => [fn () => '//evil.com', 'tma.home'],
    'absolute https' => [fn () => 'https://evil', 'tma.home'],
    'javascript' => [fn () => 'javascript:alert(1)', 'tma.home'],
    'backslash' => [fn () => '\\\\evil.com', 'tma.home'],
    'foreign order' => [fn () => 'o'.Order::factory()->telegram(tmaUser(['phone' => '+998907654321']))->create()->id, 'tma.orders'],
    'test order of own user' => [fn () => 'o'.Order::factory()->test()->create(['telegram_user_id' => TelegramUser::query()->latest('id')->value('id')])->id, 'tma.orders'],
    'hidden category' => [fn () => 'c'.Category::factory()->inactive()->create()->id, 'tma.home'],
    'hidden product' => [fn () => 'p'.Product::factory()->inactive()->create()->id, 'tma.home'],
    'order id overflow' => [fn () => 'o99999999999', 'tma.home'],
]);

it('sends a hidden product or category in the signed start_param home', function (Closure $param) {
    $user = tmaUser();

    $redirect = tmaAuth(tmaInitData($user->telegram_id, ['start_param' => $param()]))->assertOk()->json('redirect');

    expect($redirect)->toBe(route('tma.home', ['locale' => 'uz']));
})->with([
    'inactive product' => [fn () => 'p'.Product::factory()->inactive()->create()->id],
    'inactive category' => [fn () => 'c'.Category::factory()->inactive()->create()->id],
    'product of an inactive category' => [fn () => 'p'.Product::factory()->inCategory(Category::factory()->inactive()->create())->create()->id],
]);

it('does not echo an unsafe "next" into the bootstrap page', function (string $next) {
    $html = $this->get('/tma?'.http_build_query(['next' => $next]))->assertOk()->getContent();

    expect($html)->not->toContain('data-next=')->not->toContain('evil');
})->with(['//evil.com', 'https://evil', 'o1x', '"><script>evil()</script>']);

// ---------------------------------------------------------------- 12

it('does not let a Mini App cart token work as the site cart cookie', function () {
    $product = Product::factory()->create();
    $user = tmaUser();
    $tmaCart = TmaCart::for($user, 'uz');
    CartItem::factory()->for($tmaCart)->create(['product_id' => $product->id, 'qty' => 3]);

    expect(fn () => app(AddToCart::class)->handle($tmaCart->token, $product->id, null, 1, 'uz'))->toThrow(ValidationException::class);

    $html = $this->withCookie(CartToken::COOKIE, $tmaCart->token)->get('/uz/cart')->assertOk()->getContent();

    expect($html)->not->toContain($product->sku)
        ->not->toContain('data-cart-count>')
        ->and($tmaCart->fresh()->items()->sole()->qty)->toBe(3)
        ->and(Cart::query()->forSite()->count())->toBe(0);

    $this->withCookie(CartToken::COOKIE, $tmaCart->token)->get('/uz')->assertOk()->assertSee('data-cart-counter', false)->assertDontSee('data-cart-count>', false);
});

it('adds to a fresh site cart, never to the Mini App cart, when the cookie holds a Mini App token', function () {
    $product = Product::factory()->create();
    $tmaCart = TmaCart::for(tmaUser(), 'uz');
    request()->cookies->set(CartToken::COOKIE, $tmaCart->token);
    // Like the site cart tests: the counter's links need the locale route default.
    URL::defaults(['locale' => 'uz']);

    Livewire::test(Counter::class)->call('addFromCard', $product->id);

    expect($tmaCart->fresh()->items()->count())->toBe(0);
});

// ---------------------------------------------------------------- 13

it('rejects a site session cookie value copied into poson_tma_session', function () {
    $user = tmaUser();
    $siteCookie = collect($this->get('/uz')->assertOk()->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    $siteId = CookieValuePrefix::remove(Crypt::decryptString($siteCookie->getValue()));

    // The site session id now also holds a valid Mini App sign-in, so only the cookie decides.
    $now = now()->getTimestamp();
    app('session')->driver()->getHandler()->write($siteId, json_encode([
        TmaSession::USER => $user->id, TmaSession::TELEGRAM_ID => $user->telegram_id, TmaSession::AUTH_AT => $now, TmaSession::SEEN_AT => $now,
    ]));

    $this->flushSession();
    $this->withUnencryptedCookie(ConfigureTmaSession::COOKIE, $siteCookie->getValue())->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));

    // Control: the same session id under the Mini App cookie name is accepted.
    $own = Crypt::encryptString(CookieValuePrefix::create(ConfigureTmaSession::COOKIE, Crypt::getKey()).$siteId);
    $this->flushSession();
    $this->withUnencryptedCookie(ConfigureTmaSession::COOKIE, $own)->get('/tma/uz')->assertOk();
});

// ---------------------------------------------------------------- 14

it('keeps staff signed in at /admin after they visit the Mini App', function () {
    $staff = staffUser();
    $this->actingAs($staff);
    $this->get(route('admin.dashboard'))->assertOk();

    $this->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));
    $this->get('/tma')->assertOk();
    tmaAuth(tmaInitData(777000333))->assertStatus(200);
    $this->post('/tma/uz/checkout')->assertRedirect();

    $this->assertAuthenticatedAs($staff);
});

it('never signs a Mini App user in as a staff or site user', function () {
    $user = tmaUser();
    tmaAuth(tmaInitData($user->telegram_id))->assertOk();

    $this->assertGuest();
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

// ---------------------------------------------------------------- 15

it('still injects the Livewire assets on a site page after a Mini App request in the same process', function () {
    $before = (bool) config('livewire.inject_assets', true);
    // Livewire adds its script to the first page of a process only; a fresh request in production starts clean.
    $site = function (): TestResponse {
        Livewire::flushState();

        return test()->get('/uz')->assertOk();
    };

    $site()->assertSee('livewire.js', false);

    tmaSignIn(tmaUser());
    $this->get('/tma/uz')->assertOk()->assertDontSee('livewire', false);
    expect(config('livewire.inject_assets'))->toBe($before);
    $site()->assertSee('livewire.js', false)->assertSeeLivewire('site.lead-form');

    // A Mini App request that ends in a redirect or an error restores it too.
    $this->flushSession();
    $this->get('/tma/uz')->assertRedirect();
    $this->get('/tma/uz/p/no-such-product')->assertRedirect();
    expect(config('livewire.inject_assets'))->toBe($before);
    $site()->assertSee('livewire.js', false);

    tmaSignIn(tmaUser(['phone' => '+998907654321']));
    $this->get('/tma/uz/p/no-such-product')->assertNotFound();
    expect(config('livewire.inject_assets'))->toBe($before);
    $site()->assertSee('livewire.js', false);
});

// ---------------------------------------------------------------- 18

it('places one order and fires one OrderPlaced for two checkout POSTs on the same cart', function () {
    Event::fake([OrderPlaced::class]);
    $user = tmaUser(['name' => 'Aziz Karimov']);
    tmaSignIn($user);
    $cart = abuseCartWith($user);
    $before = RateLimiter::attempts(ForeignPhoneLimit::KEY);

    abuseCheckout($cart)->assertSessionHasNoErrors();
    $second = abuseCheckout($cart);

    $order = Order::query()->sole();
    $second->assertRedirect(route('tma.orders.show', ['locale' => 'uz', 'order' => $order->id]));
    Event::assertDispatchedTimes(OrderPlaced::class, 1);
    expect($order->items()->count())->toBe(1)
        ->and(RateLimiter::attempts('tma-checkout:'.$user->id))->toBe(1)
        ->and(RateLimiter::attempts('checkout-phone:'.sha1((string) $user->phone)))->toBe(1)
        ->and(RateLimiter::attempts(ForeignPhoneLimit::KEY))->toBe($before);
});

// ---------------------------------------------------------------- 21

it('slides the idle window: 119 minutes passes and extends seen_at, 121 minutes since the last request is refused', function () {
    $user = tmaUser();
    $start = now()->startOfSecond();
    $this->travelTo($start);
    tmaSignIn($user);

    $this->travelTo($start->copy()->addMinutes(119));
    $this->get('/tma/uz')->assertOk();
    expect(session(TmaSession::SEEN_AT))->toBe($start->copy()->addMinutes(119)->getTimestamp());

    // 238 minutes after sign-in, 119 after the last request: still inside the idle window.
    $this->travelTo($start->copy()->addMinutes(238));
    $this->get('/tma/uz')->assertOk();

    $this->travelTo($start->copy()->addMinutes(238 + 121));
    $this->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));
    expect(session(TmaSession::USER))->toBeNull();
});

it('does not slide a refused request: seen_at stays put when the session was already stale', function () {
    $user = tmaUser();
    $old = now()->subMinutes(121)->getTimestamp();
    tmaSignIn($user, seenAt: $old);

    $this->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));

    expect(session(TmaSession::SEEN_AT))->toBeNull();
});

// ---------------------------------------------------------------- 22

it('keys throttle:tma per user so two users behind one IP do not share a budget', function () {
    $first = tmaUser();
    $second = tmaUser(['phone' => '+998907654321']);

    foreach (range(1, 120) as $hit) {
        RateLimiter::hit(md5('tma'.'tma:u'.$first->id), 60);
    }

    tmaSignIn($first);
    $this->get('/tma/uz')->assertStatus(429);

    tmaSignIn($second);
    $this->flushSession();
    tmaSignIn($second);
    $this->get('/tma/uz')->assertOk();
});

it('keys the cart throttle per user as well', function () {
    $first = tmaUser();
    $second = tmaUser(['phone' => '+998907654321']);
    $product = Product::factory()->create();

    foreach (range(1, 30) as $hit) {
        RateLimiter::hit(md5('tma-cart'.'tma-cart:u'.$first->id), 60);
    }

    tmaSignIn($first);
    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus(429);

    $this->flushSession();
    tmaSignIn($second);
    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1])->assertRedirect()->assertSessionHas('tma_status', 'added');
});

// ---------------------------------------------------------------- 23

it('keeps the foreign-phone kill switch for Mini App requests', function (string $entry) {
    Event::fake([LeadCreated::class]);
    $user = tmaUser(['phone' => '+77011234567']);
    tmaSignIn($user);
    config(['site.foreign_phones_enabled' => false]);

    $response = abuseSubmitRequest($entry, $user);

    $response->assertSessionHasErrors();
    expect(Lead::query()->count())->toBe(0);
})->with(['product quote', 'service request', 'cart quote']);

it('counts Mini App requests from a foreign phone against the shared foreign-phone cap', function (string $entry) {
    Event::fake([LeadCreated::class]);
    config(['site.foreign_phone_limit_per_hour' => 1]);
    RateLimiter::clear(ForeignPhoneLimit::KEY);
    $user = tmaUser(['phone' => '+77011234567']);
    tmaSignIn($user);

    abuseSubmitRequest($entry, $user);
    $second = abuseSubmitRequest($entry, $user);

    expect(Lead::query()->count())->toBe(1)
        ->and(RateLimiter::attempts(ForeignPhoneLimit::KEY))->toBeGreaterThanOrEqual(1);
    $second->assertSessionHasErrors();
})->with(['product quote', 'service request', 'cart quote']);

it('refuses a foreign-phone Mini App request once the cap is used up by the site, and keeps Uzbek numbers working', function (string $entry) {
    Event::fake([LeadCreated::class]);
    config(['site.foreign_phone_limit_per_hour' => 1]);
    RateLimiter::clear(ForeignPhoneLimit::KEY);
    RateLimiter::hit(ForeignPhoneLimit::KEY, 3600);

    $foreign = tmaUser(['phone' => '+77011234567']);
    tmaSignIn($foreign);
    abuseSubmitRequest($entry, $foreign)->assertSessionHasErrors();
    expect(Lead::query()->count())->toBe(0);

    $this->flushSession();
    $uzbek = tmaUser(['phone' => '+998907654321']);
    tmaSignIn($uzbek);
    abuseSubmitRequest($entry, $uzbek)->assertSessionHasNoErrors();
    expect(Lead::query()->count())->toBe(1);
})->with(['product quote', 'service request', 'cart quote']);

/**
 * One Mini App request (quote, service request or cart quote) as the signed-in user.
 */
function abuseSubmitRequest(string $entry, TelegramUser $user): TestResponse
{
    if ($entry === 'cart quote') {
        CartItem::factory()->for(TmaCart::for($user, 'uz'))->create(['product_id' => Product::factory()->create()->id, 'qty' => 1]);

        return test()->post('/tma/uz/cart/quote');
    }

    if ($entry === 'service request') {
        $product = Product::factory()->inCategory(Category::query()->firstWhere('slug', 'scanners') ?? Category::factory()->create(['slug' => 'scanners']))->create(['model' => 'DS2208']);

        return test()->post('/tma/uz/p/'.$product->slug.'/service', ['message' => 'Skaner shtrix-kodni oʻqimayapti', 'preferred_contact' => 'telegram']);
    }

    return test()->post('/tma/uz/p/'.Product::factory()->create()->slug.'/quote');
}
