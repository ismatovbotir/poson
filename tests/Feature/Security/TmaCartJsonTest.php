<?php

use App\Events\Orders\OrderPlaced;
use App\Http\Controllers\Tma\CheckoutController;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\RollType;
use App\Rules\BrandSafeText;
use App\Support\Cart\CartPricer;
use App\Support\Money;
use App\Support\Orders\CheckoutSettings;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaIcons;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Symfony\Component\Mime\MimeTypes;

require_once __DIR__.'/../../Helpers/MiniApp.php';

/*
| Plan 45 part C (security sign-off 2026-11-26): the Mini App cart answering
| JSON to tma.js (MA-42 … MA-48, MA-54, MA-55), abuse cases 1-10, 16 and 17.
| Cases 11-15 (facet cache, rebuild button, schedule) belong to part B.
*/
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    config(['site.cart_enabled' => true]);
    Event::fake([OrderPlaced::class]);
    $this->tgUser = tmaUser();
    tmaSignIn($this->tgUser);
});

/**
 * The open cart of the signed-in user with one line of the product.
 */
function p45Cart(object $test, Product $product, int $qty = 1): Cart
{
    $cart = TmaCart::for($test->tgUser, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => $product->id, 'qty' => $qty]);

    return $cart;
}

/**
 * MA-48: what the Mini App sprite may hold (elements, attributes, references); the list of problems.
 *
 * @return list<string>
 */
function p45SpriteProblems(string $svg): array
{
    $problems = [];
    $allowed = ['svg', 'symbol', 'path', 'g', 'circle', 'rect', 'line', 'polyline'];

    preg_match_all('/<\s*([a-zA-Z:-]+)/', $svg, $tags);

    foreach (array_unique($tags[1]) as $tag) {
        if (! in_array(strtolower($tag), $allowed, true)) {
            $problems[] = 'element '.$tag;
        }
    }

    if (preg_match('/\son[a-z]+\s*=/i', $svg)) {
        $problems[] = 'event handler';
    }

    if (preg_match('/\sstyle\s*=/i', $svg)) {
        $problems[] = 'style attribute';
    }

    if (preg_match('/(xlink:)?href\s*=/i', $svg) || preg_match('/url\s*\(/i', $svg)) {
        $problems[] = 'reference';
    }

    if (preg_match('/<!(DOCTYPE|ENTITY)|<\?/i', $svg)) {
        $problems[] = 'doctype or processing instruction';
    }

    return $problems;
}

// 1 -------------------------------------------------------------------------

it('refuses a JSON cart write without a CSRF token or from a foreign origin (abuse case 1)', function () {
    $product = Product::factory()->create();
    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        $this->postJson('https://poson.example.uz/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(419)
            ->assertJsonStructure(['message']);

        $this->withSession([...session()->all(), '_token' => 'p45-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'p45-token', 'Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site'])
            ->postJson('https://poson.example.uz/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(419);
    } finally {
        Request::setTrustedHosts([]);
        app()['env'] = $previous;
    }

    expect(CartItem::query()->count())->toBe(0);
});

it('refuses a cart write from another origin fail-closed and logs only the route and the two headers (abuse case 1, MA-42)', function (array $headers, int $status) {
    $product = Product::factory()->create();
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = [$message->message, $message->context];
    });

    $this->withHeaders($headers)->postJson('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus($status);

    if ($status === 419) {
        expect(CartItem::query()->count())->toBe(0)
            ->and($logged)->toHaveCount(1)
            ->and($logged[0][0])->toBe('tma.cart.origin_refused')
            ->and($logged[0][1])->toBe([
                'route' => 'tma.cart.add',
                'origin' => $headers['Origin'] ?? null,
                'sec_fetch_site' => $headers['Sec-Fetch-Site'] ?? null,
            ]);
    } else {
        expect(CartItem::query()->count())->toBe(1)
            ->and(collect($logged)->pluck(0))->not->toContain('tma.cart.origin_refused');
    }
})->with([
    'foreign Origin only' => [['Origin' => 'https://evil.example'], 419],
    'Sec-Fetch-Site cross-site only' => [['Sec-Fetch-Site' => 'cross-site'], 419],
    'Sec-Fetch-Site same-site' => [['Sec-Fetch-Site' => 'same-site'], 419],
    'Origin null' => [['Origin' => 'null'], 419],
    'same host, same-origin' => [['Origin' => 'http://localhost', 'Sec-Fetch-Site' => 'same-origin'], 200],
    'same host, no Sec-Fetch-Site (old iOS)' => [['Origin' => 'http://localhost'], 200],
    'no headers at all' => [[], 200],
]);

// 2 -------------------------------------------------------------------------

it('answers JSON with the own cart only: no ids, tokens or foreign lines (abuse case 2, MA-42)', function () {
    $mine = Product::factory()->create(['price' => 200_000_00]);
    $theirs = Product::factory()->create(['price' => 900_000_00]);
    $other = Cart::factory()->forTelegramUser(tmaUser(['phone' => '+998907654321']))->create();
    CartItem::factory()->for($other)->create(['product_id' => $theirs->id, 'qty' => 7]);
    $site = Cart::factory()->create();
    CartItem::factory()->for($site)->create(['product_id' => $theirs->id, 'qty' => 3]);

    $response = $this->postJson('/tma/uz/cart', ['product_id' => $mine->id, 'qty' => 2])->assertOk();
    $own = TmaCart::current($this->tgUser);

    expect(array_keys($response->json()))->toBe(['count', 'subtotal', 'subtotalText', 'qty', 'lineKey'])
        ->and($response->json())->toBe([
            'count' => 1,
            'subtotal' => 400_000_00,
            'subtotalText' => Money::format(400_000_00),
            'qty' => 2,
            'lineKey' => 'p'.$mine->id,
        ])
        ->and($response->getContent())->not->toContain($own->token)
        ->not->toContain($other->token)
        ->not->toContain($site->token)
        ->not->toContain('p'.$theirs->id)
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('takes the line key of the answer from the stored line, never from the request (MA-42)', function () {
    $product = Product::factory()->create();

    $this->postJson('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1, 'lineKey' => 'p999', 'line_key' => 'p999'])
        ->assertOk()
        ->assertJsonPath('lineKey', 'p'.$product->id);
});

// 3 -------------------------------------------------------------------------

it('refuses forged line keys on update and remove without creating a line (abuse case 3)', function (string $key, int $status) {
    $product = Product::factory()->create();
    p45Cart($this, $product);
    $foreign = Product::factory()->create();
    $other = Cart::factory()->forTelegramUser(tmaUser(['phone' => '+998907654322']))->create();
    $otherLine = CartItem::factory()->for($other)->create(['product_id' => $foreign->id, 'qty' => 4]);
    $key = str_replace('{foreign}', (string) $foreign->id, $key);
    $before = CartItem::query()->count();

    foreach (['/tma/uz/cart/'.$key, '/tma/uz/cart/'.$key.'/remove'] as $url) {
        $response = $this->postJson($url, ['qty' => 2])->assertStatus($status);

        if ($status === 422) {
            $response->assertJsonPath('message', __('checkout.cart.errors.line_missing', [], 'uz'));
        }
    }

    expect(CartItem::query()->count())->toBe($before)
        ->and($otherLine->fresh()->qty)->toBe(4);
})->with([
    'another user\'s line' => ['p{foreign}', 422],
    'leading zero' => ['p01', 422],
    'roll type zero' => ['p1-r0', 422],
    'unknown roll type' => ['p1-r99', 422],
    'eleven digits' => ['p99999999999', 404],
    'encoded slash' => ['p1%2F', 404],
]);

// 4 -------------------------------------------------------------------------

it('refuses quantity attacks with a 422 and no TypeError (abuse case 4, MA-44)', function (string $action, array $input) {
    $product = Product::factory()->create(['min_qty' => 3]);
    p45Cart($this, $product, Cart::MAX_QTY - 1);
    $url = $action === 'add' ? '/tma/uz/cart' : '/tma/uz/cart/p'.$product->id;

    $this->postJson($url, ['product_id' => $product->id, ...$input])->assertStatus(422)->assertJsonStructure(['message']);

    expect(CartItem::query()->sole()->qty)->toBe(Cart::MAX_QTY - 1);
})->with([
    'add zero' => ['add', ['qty' => 0]],
    'add negative' => ['add', ['qty' => -1]],
    'add over the cap' => ['add', ['qty' => Cart::MAX_QTY + 1]],
    'add an array' => ['add', ['qty' => ['5']]],
    'add pushing the line over the cap' => ['add', ['qty' => 5]],
    'add a step parameter' => ['add', ['qty' => 'abc', 'step' => 5]],
    'update zero' => ['update', ['qty' => 0]],
    'update negative' => ['update', ['qty' => -1]],
    'update over the cap' => ['update', ['qty' => Cart::MAX_QTY + 1]],
    'update below min_qty' => ['update', ['qty' => 2]],
    'update an array' => ['update', ['qty' => ['5']]],
]);

// 5 -------------------------------------------------------------------------

it('refuses a crafted add for a product the cart can\'t take (abuse case 5, MA-44)', function (Closure $make, Closure $input) {
    $product = $make();

    $this->postJson('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 2, ...$input($product)])
        ->assertStatus(422)
        ->assertJsonPath('message', __('checkout.cart.errors.unavailable', [], 'uz'));

    expect(CartItem::query()->count())->toBe(0);
})->with([
    'quote only' => [fn () => Product::factory()->quoteOnly()->create(), fn () => []],
    'price on request' => [fn () => Product::factory()->priceOnRequest()->create(), fn () => []],
    'out of stock' => [fn () => Product::factory()->create(['stock_status' => 'out_of_stock']), fn () => []],
    'hidden product' => [fn () => Product::factory()->inactive()->create(), fn () => []],
    'hidden category' => [fn () => Product::factory()->inCategory(Category::factory()->inactive()->create())->create(), fn () => []],
    'roll product without a roll' => [fn () => Product::factory()->perLabel()->withRollTypes()->create(), fn () => []],
    'foreign roll' => [fn () => Product::factory()->perLabel()->withRollTypes()->create(), fn () => ['roll_type_id' => RollType::factory()->create()->id]],
    'inactive roll' => [fn () => Product::factory()->perLabel()->withRollTypes([RollType::factory()->inactive()->create()])->create(), fn (Product $product) => ['roll_type_id' => $product->rollTypes()->first()->id]],
    'a roll on a simple product' => [fn () => Product::factory()->create(), fn () => ['roll_type_id' => RollType::factory()->create()->id]],
]);

it('refuses an update of a line that can no longer be bought (MA-44)', function () {
    $product = Product::factory()->create();
    p45Cart($this, $product);
    $product->forceFill(['sale_mode' => 'quote'])->save();

    $this->postJson('/tma/uz/cart/p'.$product->id, ['qty' => 2])->assertStatus(422);

    expect(CartItem::query()->sole()->qty)->toBe(1);
});

// 6 -------------------------------------------------------------------------

it('shows a price change at once in the cart bar, the JSON subtotal and checkout (abuse case 6, MA-45)', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->withIkpu()->inCategory($category)->create(['price' => 100_000_00]);
    p45Cart($this, $product);

    // Warm every cache the pages use, then change the price without model events (no cache bump).
    $this->get('/tma/uz/c/'.$category->slug)->assertOk()->assertSee(Money::format(100_000_00));
    Product::query()->whereKey($product->id)->update(['price' => 300_000_00]);

    $listing = $this->get('/tma/uz/c/'.$category->slug)->assertOk()->getContent();
    preg_match('#<div class="tma-bar".*?</div>#s', $listing, $bar);

    expect($bar[0] ?? '')->toContain(Money::format(300_000_00))->not->toContain(Money::format(100_000_00));

    $this->postJson('/tma/uz/cart/p'.$product->id, ['qty' => 2])
        ->assertOk()
        ->assertJsonPath('subtotal', 600_000_00)
        ->assertJsonPath('subtotalText', Money::format(600_000_00));

    $this->get('/tma/uz/checkout')->assertOk()->assertSee(Money::format(600_000_00));
});

it('leaves lines that can\'t be bought out of the cart bar subtotal (MA-45)', function () {
    $category = Category::factory()->create();
    $kept = Product::factory()->inCategory($category)->create(['price' => 100_000_00]);
    $hidden = Product::factory()->inCategory($category)->create(['price' => 500_000_00]);
    $cart = p45Cart($this, $kept);
    CartItem::factory()->for($cart)->create(['product_id' => $hidden->id, 'qty' => 1]);
    $hidden->forceFill(['is_active' => false])->save();

    $summary = TmaCart::summary($this->tgUser);

    expect($summary->count)->toBe(2)
        ->and($summary->subtotal)->toBe(100_000_00)
        ->and($summary->subtotal)->toBe(app(CartPricer::class)->price($cart->fresh())->subtotal);
});

// 7 -------------------------------------------------------------------------

it('answers an expired session with 401 JSON, never a redirect to HTML (abuse case 7, MA-43)', function (Closure $session) {
    $product = Product::factory()->create();
    $session($this);

    foreach (['/tma/uz/cart' => ['product_id' => $product->id, 'qty' => 1], '/tma/uz/cart/p'.$product->id => ['qty' => 2], '/tma/uz/cart/p'.$product->id.'/remove' => []] as $url => $input) {
        $response = $this->postJson($url, $input);

        $response->assertStatus(401)->assertJsonPath('message', __('tma.session.expired', [], 'uz'));
        expect($response->headers->get('Content-Type'))->toContain('application/json')
            ->and($response->headers->has('Location'))->toBeFalse();
    }

    // A page still goes back to the bootstrap.
    $this->get('/tma/uz')->assertRedirect();
})->with([
    'no session' => [fn (object $test) => $test->flushSession()],
    'idle too long' => [fn (object $test) => tmaSignIn($test->tgUser, seenAt: now()->subHours(3)->getTimestamp())],
    'banned since' => [fn (object $test) => $test->tgUser->forceFill(['banned_at' => now()])->save()],
]);

// 8 -------------------------------------------------------------------------

it('gives the 31st cart write in a minute a 429 JSON (abuse case 8)', function () {
    $product = Product::factory()->create();
    p45Cart($this, $product);

    for ($i = 1; $i <= 30; $i++) {
        $this->postJson('/tma/uz/cart/p'.$product->id, ['qty' => 1 + ($i % 3)])->assertOk();
    }

    $this->postJson('/tma/uz/cart/p'.$product->id, ['qty' => 2])
        ->assertStatus(429)
        ->assertJsonStructure(['message']);
});

// 9 -------------------------------------------------------------------------

it('redirects unless the request really wants JSON (abuse case 9)', function (array $headers) {
    $product = Product::factory()->create();

    $response = $this->withHeaders($headers)->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1]);

    $response->assertRedirect(route('tma.product', ['locale' => 'uz', 'product' => $product->slug]));
    expect($response->headers->get('Content-Type'))->not->toContain('application/json');
})->with([
    'any type' => [['Accept' => '*/*']],
    'no accept' => [['Accept' => '']],
    'html first' => [['Accept' => 'text/html, application/json;q=0.9']],
    'xhr without json' => [['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']],
]);

it('builds the redirect without JavaScript on the server, never from the request (MA-46)', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create();
    $hidden = Category::factory()->inactive()->create();
    $product404 = route('tma.product', ['locale' => 'uz', 'product' => $product->slug]);

    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1, 'from' => 'c'.$category->id.'-2'])
        ->assertRedirect(route('tma.category', ['locale' => 'uz', 'category' => $category->slug, 'page' => 2]).'#p-'.$product->id);

    foreach (['https://evil.example', '//evil.example', 'c'.$hidden->id, 'c0', 'c1-0', 'c'.$category->id.'-99999', '../cart', 'cart?x=1'] as $from) {
        $this->withHeaders(['Referer' => 'https://evil.example/'])
            ->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1, 'from' => $from])
            ->assertRedirect($product404);
    }

    $this->post('/tma/uz/cart/p'.$product->id, ['qty' => 1, 'from' => 'p'])->assertRedirect($product404);
    $this->post('/tma/uz/cart/p'.$product->id, ['qty' => 1, 'from' => 'https://evil.example'])->assertRedirect(route('tma.cart', ['locale' => 'uz']));
    $this->post('/tma/uz/cart/p'.$product->id.'/remove', ['from' => 'c'.$category->id])
        ->assertRedirect(route('tma.category', ['locale' => 'uz', 'category' => $category->slug]).'#p-'.$product->id);
});

// 10 ------------------------------------------------------------------------

it('ships a sprite of plain shapes only, and the check catches a script or style= (abuse case 10, MA-48)', function () {
    $svg = file_get_contents(resource_path('svg/tma-icons.svg'));

    expect(p45SpriteProblems($svg))->toBe([])
        ->and(p45SpriteProblems(str_replace('</svg>', '<script>alert(1)</script></svg>', $svg)))->not->toBe([])
        ->and(p45SpriteProblems(str_replace('<path ', '<path style="fill:red" ', $svg)))->not->toBe([])
        ->and(p45SpriteProblems(str_replace('<path ', '<path onload="x()" ', $svg)))->not->toBe([])
        ->and(p45SpriteProblems(str_replace('</svg>', '<foreignObject></foreignObject></svg>', $svg)))->not->toBe([])
        ->and(p45SpriteProblems(str_replace('</svg>', '<use href="https://evil.example/x.svg#a"/></svg>', $svg)))->not->toBe([]);
});

it('references the sprite by a same-origin /build path, never data: or another host (abuse case 10, MA-48)', function () {
    $previous = public_path();
    $dirs = [];
    $useManifest = function (string $file) use (&$dirs): void {
        $public = sys_get_temp_dir().'/p45-public-'.uniqid();
        mkdir($public.'/build', 0777, true);
        file_put_contents($public.'/build/manifest.json', json_encode([TmaIcons::SPRITE => ['file' => $file, 'src' => TmaIcons::SPRITE]]));
        $dirs[] = $public;
        app()->usePublicPath($public);
        app()->instance(Vite::class, new Vite);
        ViteFacade::clearResolvedInstances();
    };

    try {
        config(['app.asset_url' => 'https://cdn.example']);
        $useManifest('assets/tma-icons-AbC123.svg');

        expect(TmaIcons::spriteUrl())->toBe('/build/assets/tma-icons-AbC123.svg')
            ->and(TmaIcons::href('cat-ribbon'))->toBe('/build/assets/tma-icons-AbC123.svg#i-cat-ribbon')
            ->and(TmaIcons::href('../../evil'))->toBe('/build/assets/tma-icons-AbC123.svg#i-grid')
            ->and(TmaIcons::href('cat-ribbon'))->not->toContain('data:')->not->toContain('cdn.example');

        // Not an .svg under /build: the page inlines the symbols it used instead.
        $useManifest('assets/x.js');

        expect(TmaIcons::spriteUrl())->toBeNull()
            ->and(TmaIcons::href('cart'))->toBe('#i-cart')
            ->and(TmaIcons::inlineSprite())->toContain('<symbol id="i-cart"')->not->toContain('i-cat-ribbon');
    } finally {
        app()->usePublicPath($previous);
        app()->forgetInstance(Vite::class);
        ViteFacade::clearResolvedInstances();

        foreach ($dirs as $dir) {
            unlink($dir.'/build/manifest.json');
            rmdir($dir.'/build');
            rmdir($dir);
        }
    }

    expect((new MimeTypes)->getMimeTypes('svg'))->toContain('image/svg+xml');
});

// 16 ------------------------------------------------------------------------

it('keeps every new plan 45 string brand safe in uz, ru and en, and a forbidden word fails (abuse case 16, MA-54)', function (string $locale) {
    $strings = Arr::only(Arr::dot(require lang_path("{$locale}/tma.php")), array_filter(
        array_keys(Arr::dot(require lang_path("{$locale}/tma.php"))),
        fn (string $key): bool => preg_match('/^(bar|toast|session|stepper|card)\.|^home\.(why|why_title|more)|^category\.(breadcrumbs|filters|remove_filter)|^product\.(photos|in_cart|rolls_in_cart|open_cart|all_specs|assurance)|^cart\.(rolls_labels|per_unit)|^checkout\.(summary|tin_hint|steps)|^orders\.(more_items|items|received|next|continue)/', $key) === 1,
    ));

    expect(count($strings))->toBeGreaterThanOrEqual(30);

    foreach ($strings as $key => $value) {
        expect(BrandSafeText::violates((string) $value))->toBeFalse("{$locale}.{$key}")
            ->and((string) $value)->not->toMatch('/[-−]\s?\d+\s?%/u', "{$locale}.{$key}");

        if ($locale === 'uz') {
            expect(preg_match("/[a-z]['‘’][a-z]/iu", (string) $value))->toBe(0, "uz.{$key}: use ʻ / ʼ");
        }

        foreach (['chegirma', 'скидки', 'cheapest', 'arzon'] as $poison) {
            expect(BrandSafeText::violates($value.' '.$poison))->toBeTrue("{$locale}.{$key} + {$poison}");
        }
    }
})->with(['uz', 'ru', 'en']);

// 17 ------------------------------------------------------------------------

it('never stores a company or STIR posted by an individual buyer (abuse case 17, MA-55)', function () {
    $product = Product::factory()->withIkpu()->create(['price' => 1_000_000_00]);
    $cart = p45Cart($this, $product);
    $priced = app(CartPricer::class)->price($cart->fresh());

    $this->post('/tma/uz/checkout', [
        'cart_fingerprint' => $priced->fingerprint,
        'delivery_fingerprint' => CheckoutController::deliveryFingerprint(app(CheckoutSettings::class)),
        'name' => 'Aziz Karimov',
        'buyer_type' => 'individual',
        'company_name' => 'Soxta MChJ',
        'tin' => '301234567',
        'delivery_type' => 'pickup',
        'payment_method' => 'on_receipt',
    ])->assertRedirect();

    $order = Order::query()->sole();

    expect($order->company_name)->toBeNull()
        ->and($order->tin)->toBeNull()
        ->and($order->buyer_type->value)->toBe('individual');
});

it('keeps the session user\'s cart out of the request: a forged session key is not a cart (MA-10)', function () {
    $product = Product::factory()->create();
    $victim = tmaUser(['phone' => '+998907654323']);
    $victimCart = TmaCart::for($victim, 'uz');

    $this->postJson('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1, 'cart' => $victimCart->token, TmaSession::USER => $victim->id])->assertOk();

    expect($victimCart->items()->count())->toBe(0)
        ->and(TmaCart::current($this->tgUser)->items()->count())->toBe(1);
});
