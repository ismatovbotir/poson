<?php

use App\Events\Orders\OrderPlaced;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Telegram\MiniApp\TmaCart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/*
| Plan 20 §8 performance budget (M6 check), next to the listing / product /
| cart budgets in MiniAppCatalogTest: the remaining Mini App screens stay
| within 15 queries without lazy loading and 30 KB gzipped HTML; the pages
| never poll and the front-end source never schedules a repeating request.
*/
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    config(['site.cart_enabled' => true]);
    Event::fake([OrderPlaced::class]);
    $this->tgUser = tmaUser();
    tmaSignIn($this->tgUser);
});

function m6BudgetFixtures(object $test): array
{
    Category::factory()->count(12)->create();
    $category = Category::factory()->create();
    $products = Product::factory()->count(20)->withIkpu()->inCategory($category)->create(['price' => 1_000_000_00]);

    foreach ($products as $product) {
        ProductAttributeValue::factory()->for($product)->key()->create();
    }

    $cart = TmaCart::for($test->tgUser, 'uz');

    foreach ($products->take(5) as $product) {
        CartItem::factory()->for($cart)->create(['product_id' => $product->id, 'qty' => 1]);
    }

    $orders = Order::factory()->telegram($test->tgUser)->count(15)->create();

    foreach ($orders as $order) {
        OrderItem::factory()->for($order)->count(3)->create();
    }

    return ['/tma/uz', '/tma/uz/checkout', '/tma/uz/orders', '/tma/uz/orders/'.$orders->first()->id];
}

it('keeps home, checkout, my orders and the order page within 15 queries without lazy loading', function () {
    $urls = m6BudgetFixtures($this);

    Model::preventLazyLoading();

    foreach ($urls as $url) {
        expect(countQueries(fn () => $this->get($url)->assertOk()))->toBeLessThanOrEqual(15, $url);
    }
});

it('keeps the cart, checkout, my orders and the order page within 30 KB gzipped without polling', function () {
    $urls = m6BudgetFixtures($this);

    foreach (['/tma/uz/cart', ...$urls] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(strlen(gzencode($html, 9)))->toBeLessThanOrEqual(30 * 1024, $url)
            ->and($html)->not->toContain('wire:poll')
            ->and($html)->not->toContain('http-equiv="refresh"');
    }
});

it('ships a front-end script without polling or webfonts', function () {
    $js = file_get_contents(resource_path('js/tma.js'));
    $css = file_get_contents(resource_path('css/tma.css'));

    expect($js)->not->toContain('setInterval')
        ->and($css)->not->toContain('@font-face')
        ->and($css)->not->toContain('fonts.googleapis');
});
