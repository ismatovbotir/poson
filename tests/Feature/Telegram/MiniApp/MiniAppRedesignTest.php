<?php

use App\Enums\OrderStatus;
use App\Events\Orders\OrderPlaced;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\CategoryTree;
use App\Support\Money;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaIcons;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/*
| Plan 45 part A / A2: the Mini App redesign — cards with their cart action,
| the cart bar, category icons, toolbar, cart, checkout and order screens,
| and the §8 / MA-56 budgets with the cart priced on every listing.
*/
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    config(['site.cart_enabled' => true]);
    Event::fake([OrderPlaced::class]);
    $this->tgUser = tmaUser();
    tmaSignIn($this->tgUser);
});

afterEach(fn () => Model::preventLazyLoading(false));

/**
 * The HTML of one product card on a listing.
 */
function p45Card(string $html, Product $product): string
{
    preg_match('#<article id="p-'.$product->id.'".*?</article>#s', $html, $match);

    return $match[0] ?? '';
}

// Cards ----------------------------------------------------------------------

it('gives each card the action of its kind: add, stepper, choose roll or request a quote', function () {
    $category = Category::factory()->create();
    $simple = Product::factory()->inCategory($category)->create(['min_qty' => 3]);
    $inCart = Product::factory()->inCategory($category)->create();
    $roll = Product::factory()->perLabel()->withRollTypes()->inCategory($category)->create();
    $quote = Product::factory()->quoteOnly()->inCategory($category)->create();
    $soldOut = Product::factory()->inCategory($category)->create(['stock_status' => 'out_of_stock']);
    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => $inCart->id, 'qty' => 2]);

    $html = $this->get('/tma/uz/c/'.$category->slug)->assertOk()->getContent();

    expect(p45Card($html, $simple))
        ->toContain('data-cart-form="add"')
        ->toContain('name="product_id" value="'.$simple->id.'"')
        ->toContain('name="qty" value="3"')
        ->toContain('name="from" value="c'.$category->id.'"')
        ->toContain(__('tma.product.min_qty', ['count' => 3], 'uz'))
        ->toMatch('#<form[^>]*data-cart-form="update"[^>]*hidden#');

    $card = p45Card($html, $inCart);
    expect($card)->toMatch('#<form[^>]*data-cart-form="add"[^>]*hidden#')
        ->toContain('action="'.route('tma.cart.update', ['locale' => 'uz', 'lineKey' => 'p'.$inCart->id]).'"')
        ->toContain('<output data-qty aria-live="polite">2</output>')
        ->toContain('name="qty" value="1" data-step="-1"')
        ->toContain('name="qty" value="3" data-step="1"')
        ->not->toMatch('#<form[^>]*data-cart-form="update"[^>]*hidden#');

    expect(p45Card($html, $roll))->toContain('data-cta="roll"')->not->toContain('data-cart-form')
        ->and(p45Card($html, $quote))->toContain('data-cta="quote"')->toContain('#request')->not->toContain('data-cart-form')
        ->and(p45Card($html, $soldOut))->toContain('data-cta="quote"')->toContain(__('site.stock.out_of_stock', [], 'uz'))->not->toContain('data-cart-form');
});

it('posts the minus button to remove at the minimum quantity', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['min_qty' => 2]);
    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => $product->id, 'qty' => 2]);

    $card = p45Card($this->get('/tma/uz/c/'.$category->slug)->getContent(), $product);

    expect($card)->toContain('formaction="'.route('tma.cart.remove', ['locale' => 'uz', 'lineKey' => 'p'.$product->id]).'"');
});

it('shows the key specs, a stock line, the Retail price only and no sale wording on cards', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['price' => 1_250_000_00, 'price_dealer' => 1_000_000_00, 'price_wholesale' => 900_000_00]);
    ProductAttributeValue::factory()->for($product)->key()->create();

    $card = p45Card($this->get('/tma/uz/c/'.$category->slug)->getContent(), $product);

    expect($card)->toContain('data-card-specs')
        ->toContain('data-stock')
        ->toContain(Money::format(1_250_000_00))
        ->not->toContain(Money::format(1_000_000_00))
        ->not->toContain(Money::format(900_000_00))
        ->not->toContain('<del')->not->toContain('<s>')->not->toContain('line-through')
        ->not->toMatch('/[-−]\s?\d+\s?%/u');
});

// Cart bar -------------------------------------------------------------------

it('shows the cart bar with the line count and the live subtotal on home, category, search and product', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['price' => 400_000_00, 'sku' => 'P45-BAR']);
    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => $product->id, 'qty' => 3]);

    foreach (['/tma/uz', '/tma/uz/c/'.$category->slug, '/tma/uz/search?q=P45-BAR', '/tma/uz/p/'.$product->slug] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('data-cart-bar ')
            ->toContain('data-main-button="'.__('tma.bar.label', [], 'uz').' (1) · '.Money::format(1_200_000_00).'"')
            ->toContain('<span data-bar-count>1</span>')
            ->not->toMatch('#data-cart-bar-wrap[^>]*hidden#');
    }

    expect($this->get('/tma/uz/cart')->getContent())->not->toContain('data-cart-bar');
});

it('keeps the cart bar hidden while the cart is empty and drops it while the cart is off', function () {
    expect($this->get('/tma/uz')->getContent())->toMatch('#data-cart-bar-wrap[^>]*hidden#');

    config(['site.cart_enabled' => false]);

    expect($this->get('/tma/uz')->getContent())->not->toContain('data-cart-bar')->not->toContain('data-cart-form');
});

it('makes the product page add button the main button until the product is in the cart, then shows its stepper', function () {
    $product = Product::factory()->create();

    $html = $this->get('/tma/uz/p/'.$product->slug)->getContent();
    expect($html)->toMatch('#<form[^>]*data-cart-form="add"(?![^>]*hidden)#')
        ->toMatch('#data-in-cart[^>]*hidden#')
        ->toContain('data-qty-step="-1"')
        ->toContain('data-service-strip');

    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => $product->id, 'qty' => 2]);

    $html = $this->get('/tma/uz/p/'.$product->slug)->getContent();
    expect($html)->toMatch('#<form[^>]*data-cart-form="add"[^>]*hidden#')
        ->not->toMatch('#data-in-cart[^>]*hidden#')
        ->toContain('<output data-qty aria-live="polite">2</output>');
});

it('shows a roll product\'s in-cart hint with a cart link and no stepper (plan 45)', function () {
    $product = Product::factory()->perLabel()->withRollTypes()->create();
    $rollType = $product->rollTypes()->orderByPivot('sort')->first();

    $html = $this->get('/tma/uz/p/'.$product->slug)->getContent();
    expect($html)->toMatch('#data-roll-in-cart[^>]*hidden#')->not->toContain('data-cart-form="update"');

    $this->postJson('/tma/uz/cart', ['product_id' => $product->id, 'roll_type_id' => $rollType->id, 'qty' => 3])->assertOk()->assertJsonPath('qty', 3);

    $html = $this->get('/tma/uz/p/'.$product->slug)->getContent();
    expect($html)->not->toMatch('#data-roll-in-cart[^>]*hidden#')
        ->toContain('data-roll-qty>3</span>')
        ->toContain(__('tma.product.open_cart', [], 'uz'))
        ->not->toContain('data-cart-form="update"');
});

it('puts the status flash into the fixed toast', function () {
    $product = Product::factory()->create();

    $this->post('/tma/uz/cart', ['product_id' => $product->id, 'qty' => 1]);
    $html = $this->get('/tma/uz/p/'.$product->slug)->getContent();

    expect($html)->toMatch('#<p role="status"[^>]*class="tma-toast tma-toast-on"[^>]*data-status="added"#')
        ->toContain(__('tma.status.added', [], 'uz'));
});

// Icons ----------------------------------------------------------------------

it('has every Category::ICONS name and every UI icon in the sprite (A2 parity)', function () {
    $names = array_keys(TmaIcons::symbols());

    expect(array_diff(Category::ICONS, $names))->toBe([])
        ->and(array_diff(TmaIcons::UI, $names))->toBe([])
        ->and(TmaIcons::name(null))->toBe('grid')
        ->and(TmaIcons::name('no-such-icon'))->toBe('grid')
        ->and(TmaIcons::name('cat-ribbon'))->toBe('cat-ribbon');
});

it('keeps the sprite symbols identical to the site\'s category sprite', function () {
    $blade = file_get_contents(resource_path('views/components/catalog/category-icon-sprite.blade.php'));
    preg_match_all('#<symbol id="i-(cat-[a-z0-9-]+)"[^>]*>(.*?)</symbol>#s', $blade, $matches, PREG_SET_ORDER);
    $normalize = fn (string $markup): string => preg_replace('/\s+/', ' ', trim($markup));

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [$whole, $name, $body]) {
        expect($normalize(TmaIcons::symbols()[$name] ?? ''))->toBe($normalize($whole), $name);
    }
});

it('draws category icons on home, category, breadcrumbs and search without inline styles', function () {
    $parent = Category::factory()->create(['icon' => 'cat-ribbon']);
    $child = Category::factory()->create(['parent_id' => $parent->id, 'icon' => 'cat-receipt-paper']);
    Category::factory()->create(['parent_id' => $child->id, 'icon' => 'cat-thermal-label-roll']);

    $home = $this->get('/tma/uz')->getContent();
    $category = $this->get('/tma/uz/c/'.$child->slug)->getContent();
    $search = $this->get('/tma/uz/search?q=nothing-matches-this')->getContent();

    expect($home)->toContain('href="#i-cat-ribbon"')->toContain('data-why-poson')
        ->and($category)->toContain('href="#i-cat-receipt-paper"')
        ->toContain('data-breadcrumbs')
        ->toContain('href="#i-cat-thermal-label-roll"')
        ->and($search)->toContain('data-category-chips')->toContain('href="#i-cat-ribbon"');

    foreach ([$home, $category, $search] as $html) {
        // withoutVite(): the used symbols are inlined once, nothing else.
        expect($html)->not->toContain('style=')
            ->and(substr_count($html, '<svg width="0" height="0" class="absolute"'))->toBe(1)
            ->and(preg_match_all('/<script\b/i', $html))->toBe(1);
    }
});

// Toolbar --------------------------------------------------------------------

it('shows Filters with the count, Sort and removable chips built from the normalized filters', function () {
    $category = Category::factory()->create();
    $zebra = Brand::factory()->create(['slug' => 'zebra', 'name' => 'Zebra']);
    $honeywell = Brand::factory()->create(['slug' => 'honeywell', 'name' => 'Honeywell']);
    Product::factory()->inCategory($category)->create(['brand_id' => $zebra->id]);
    Product::factory()->inCategory($category)->create(['brand_id' => $honeywell->id]);

    $html = $this->get('/tma/uz/c/'.$category->slug.'?brand[]=zebra&brand[]=honeywell&stock[]=in_stock&sort=new&evil=1')->assertOk()->getContent();

    expect($html)->toContain('data-filter-count>3<')
        ->toContain('data-sort-link')
        ->toContain(__('site.catalog.sort.new', [], 'uz'))
        ->and(substr_count($html, 'data-filter-chip'))->toBe(3)
        ->and($html)->not->toContain('evil=1');

    preg_match_all('#<a href="([^"]+)" class="tma-chip bg-tg-button#', $html, $links);
    $urls = array_map('html_entity_decode', $links[1]);

    expect(collect($urls)->contains(fn (string $url): bool => ! str_contains($url, 'zebra') && str_contains($url, 'honeywell') && str_contains($url, 'stock')))->toBeTrue()
        ->and(collect($urls)->contains(fn (string $url): bool => str_contains($url, 'zebra') && str_contains($url, 'honeywell') && ! str_contains($url, 'stock')))->toBeTrue()
        ->and(collect($urls)->every(fn (string $url): bool => str_contains($url, 'sort=new')))->toBeTrue();
});

it('opens a filter group with an active value and moves Reset to the header', function () {
    $category = Category::factory()->create();
    $zebra = Brand::factory()->create(['slug' => 'zebra']);
    Product::factory()->inCategory($category)->create(['brand_id' => $zebra->id]);

    $html = $this->get('/tma/uz/c/'.$category->slug.'/filters?brand[]=zebra')->assertOk()->getContent();

    expect($html)->toContain('data-header-link')
        ->toMatch('#<details class="tma-group"\s+open\s*>\s*<summary[^>]*>'.preg_quote(__('tma.filters.brand', [], 'uz'), '#').'#u')
        ->toContain('data-main-button="'.__('tma.filters.apply', [], 'uz').'"');
});

// Cart, checkout, orders -----------------------------------------------------

it('lays out cart lines with thumbnail, unit price, stepper, line total and a 44 px remove button', function () {
    $product = Product::factory()->create(['price' => 50_000_00, 'min_qty' => 2]);
    $cart = TmaCart::for($this->tgUser, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => $product->id, 'qty' => 3]);
    $gone = Product::factory()->create();
    CartItem::factory()->for($cart)->create(['product_id' => $gone->id, 'qty' => 1]);
    $gone->forceFill(['is_active' => false])->save();

    $html = $this->get('/tma/uz/cart')->assertOk()->getContent();

    expect($html)->toContain(__('tma.cart.per_unit', ['price' => Money::format(50_000_00)], 'uz'))
        ->toContain('name="from" value="cart"')
        ->toContain('<output data-qty aria-live="polite">3</output>')
        ->toContain('data-line-total>'.Money::format(150_000_00))
        ->toContain('class="grid size-11 place-items-center')
        ->toContain('border-l-4 border-tg-danger')
        ->toContain('data-cart-blocked')
        ->not->toContain('data-cart-form="update"');
});

it('splits checkout into four numbered sections with the summary collapsed', function () {
    CartItem::factory()->for(TmaCart::for($this->tgUser, 'uz'))->create(['product_id' => Product::factory()->withIkpu()->create(['price' => 1_000_000_00])->id, 'qty' => 1]);

    $html = $this->get('/tma/uz/checkout')->assertOk()->getContent();

    foreach (['contact', 'buyer', 'delivery', 'payment'] as $step) {
        expect($html)->toContain('id="tma-step-'.$step.'"')->toContain(__('tma.checkout.steps.'.$step, [], 'uz'));
    }

    expect($html)->toContain('<details class="tma-card" data-summary>')
        ->toContain('data-pay-for=')
        ->toMatch('#data-no-method[^>]*hidden#')
        ->not->toContain('style=');
});

it('lists orders with a status badge, the item count and the first item', function () {
    $order = Order::factory()->telegram($this->tgUser)->create(['status' => OrderStatus::Confirmed]);
    OrderItem::factory()->for($order)->create(['name' => 'Zebra ZD421']);
    OrderItem::factory()->for($order)->create(['name' => 'Ribbon 110×300']);

    $html = $this->get('/tma/uz/orders')->assertOk()->getContent();

    expect($html)->toContain('tma-badge')
        ->toContain('data-order-first-item>Zebra ZD421')
        ->toContain(__('tma.orders.items', ['count' => 2], 'uz'));
});

it('shows the received block after placing and a status stepper, with Cancelled as a badge', function () {
    $order = Order::factory()->telegram($this->tgUser)->create(['status' => OrderStatus::Shipped]);
    OrderItem::factory()->for($order)->create();

    $html = $this->withSession(['tma_status' => 'placed'])->get('/tma/uz/orders/'.$order->id)->assertOk()->getContent();

    expect($html)->toContain('data-placed')
        ->toContain(__('tma.orders.received', ['number' => $order->number], 'uz'))
        ->toContain(__('tma.orders.next.confirm', [], 'uz'))
        ->toContain('data-status-stepper')
        ->and(substr_count($html, 'data-current'))->toBe(1);

    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    tmaSignIn($this->tgUser);

    expect($this->get('/tma/uz/orders/'.$order->id)->getContent())->toContain('data-status-cancelled')->not->toContain('data-status-stepper');
});

// Budgets (MA-56, plan 20 §8) --------------------------------------------------

it('keeps the listing within 15 queries with the cart bar and in-cart quantities, without lazy loading', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(20)->inCategory($category)->create();

    foreach ($products as $listed) {
        ProductAttributeValue::factory()->for($listed)->key()->create();
    }

    $cart = TmaCart::for($this->tgUser, 'uz');

    foreach ($products->take(5) as $line) {
        CartItem::factory()->for($cart)->create(['product_id' => $line->id, 'qty' => 2]);
    }

    // A label product line too: the roll types are the only extra query.
    $roll = Product::factory()->perLabel()->withRollTypes()->create();
    CartItem::factory()->for($cart)->roll($roll->rollTypes()->first())->create(['product_id' => $roll->id, 'qty' => 2]);

    Model::preventLazyLoading();

    $html = '';
    $queries = countQueries(function () use ($category, &$html) {
        $html = $this->get('/tma/uz/c/'.$category->slug)->assertOk()->getContent();
    });

    expect($queries)->toBeLessThanOrEqual(15)
        ->and(substr_count($html, '<output data-qty aria-live="polite">2</output>'))->toBe(5)
        ->and($html)->toContain('<span data-bar-count>6</span>')
        ->and(countQueries(fn () => $this->get('/tma/uz/p/'.$products->first()->slug)->assertOk()))->toBeLessThanOrEqual(15)
        ->and(countQueries(fn () => $this->get('/tma/uz/search?q='.urlencode($products->first()->sku))->assertOk()))->toBeLessThanOrEqual(15)
        ->and(countQueries(fn () => $this->get('/tma/uz')->assertOk()))->toBeLessThanOrEqual(15);
});

it('keeps each JSON cart endpoint within 12 queries, without lazy loading', function () {
    $products = Product::factory()->count(6)->create();
    $cart = TmaCart::for($this->tgUser, 'uz');

    foreach ($products->take(5) as $line) {
        CartItem::factory()->for($cart)->create(['product_id' => $line->id, 'qty' => 1]);
    }

    $new = $products->last();
    // The category tree is cached in production (CategoryTree); load it as a warm request would find it.
    CategoryTree::nodes();
    Model::preventLazyLoading();

    expect(countQueries(fn () => $this->postJson('/tma/uz/cart', ['product_id' => $new->id, 'qty' => 1])->assertOk()))->toBeLessThanOrEqual(12)
        ->and(countQueries(fn () => $this->postJson('/tma/uz/cart', ['product_id' => $new->id, 'qty' => 1])->assertOk()))->toBeLessThanOrEqual(12)
        ->and(countQueries(fn () => $this->postJson('/tma/uz/cart/p'.$new->id, ['qty' => 3])->assertOk()))->toBeLessThanOrEqual(12)
        ->and(countQueries(fn () => $this->postJson('/tma/uz/cart/p'.$new->id.'/remove')->assertOk()))->toBeLessThanOrEqual(12)
        ->and(countQueries(fn () => $this->postJson('/tma/uz/cart', ['product_id' => $new->id, 'qty' => 0])->assertStatus(422)))->toBeLessThanOrEqual(12);
});

it('keeps the redesigned pages within 30 KB gzipped without inline styles or polling', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(20)->inCategory($category)->create();
    Category::factory()->count(12)->create();
    $cart = TmaCart::for($this->tgUser, 'uz');

    foreach ($products->take(5) as $line) {
        CartItem::factory()->for($cart)->create(['product_id' => $line->id, 'qty' => 1]);
    }

    foreach (['/tma/uz', '/tma/uz/c/'.$category->slug, '/tma/uz/c/'.$category->slug.'/filters', '/tma/uz/search?q=SKU', '/tma/uz/p/'.$products->first()->slug, '/tma/uz/cart', '/tma/uz/checkout'] as $url) {
        $response = $this->get($url);
        $html = $response->isRedirect() ? '' : $response->assertOk()->getContent();

        expect(strlen(gzencode($html, 9)))->toBeLessThanOrEqual(30 * 1024, $url)
            ->and($html)->not->toContain('style=')
            ->and($html)->not->toMatch('/\\bset(Interval|Timeout)\\s*\\(/');
    }
});

it('writes tma.js with textContent and data- attributes only: no innerHTML, no style, no timers (MA-47)', function () {
    $js = file_get_contents(resource_path('js/tma.js'));

    expect($js)->not->toContain('innerHTML')
        ->not->toContain('outerHTML')
        ->not->toContain('insertAdjacentHTML')
        ->not->toContain('document.write')
        ->not->toContain('.style')
        ->not->toContain('eval(')
        ->not->toMatch('/\\bset(Interval|Timeout)\\s*\\(/')
        ->toContain("Accept: 'application/json'")
        ->toContain('textContent');
});

it('never re-sends an add: only update and remove fall back to the plain form (MA-43)', function () {
    $js = file_get_contents(resource_path('js/tma.js'));

    expect($js)->toContain("kind !== 'add'")
        ->toContain('response.redirected')
        ->toContain('response.status === 401 || response.status === 419');
});

it('never re-sends a failed add and falls back to the plain form once for an update (MA-43, behaviour)', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is not installed.');
    }

    $result = Process::run([$node, base_path('tests/js/tma-cart-resubmit.mjs'), resource_path('js/tma.js')])->throw();

    expect(json_decode($result->output(), true))->toBe([
        'add' => ['fetches' => 1, 'resubmits' => 0],
        'update' => ['fetches' => 1, 'resubmits' => 1],
    ]);
});
