<?php

use App\Enums\PriceLevel;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Cart\CartPricer;
use App\Support\Cart\PricedLine;
use App\Support\Catalog\RollPricing;

/**
 * A label product at 12 soʻm per label (Dealer 11, Wholesale 10) in a 1000-label roll type.
 *
 * @return array{0: Product, 1: RollType}
 */
function pricedLabelProduct(): array
{
    $rollType = RollType::factory()->size(1000, '40.0')->create();
    $product = Product::factory()->perLabel(12)->withRollTypes([$rollType])->create(['min_qty' => 2]);

    return [$product, $rollType];
}

it('prices product lines at the Retail price × qty and sums the subtotal', function () {
    $cart = Cart::factory()->create();
    $scanner = Product::factory()->create(['price' => 125_000_000, 'price_dealer' => 100_000_000, 'price_wholesale' => 90_000_000]);
    $printer = Product::factory()->create(['price' => 300_000_000]);
    CartItem::factory()->for($cart)->for($scanner)->create(['qty' => 2]);
    CartItem::factory()->for($cart)->for($printer)->create(['qty' => 1]);

    $priced = app(CartPricer::class)->price($cart);

    expect($priced->count())->toBe(2)
        ->and($priced->lines[0]->unitPrice)->toBe(125_000_000)
        ->and($priced->lines[0]->total)->toBe(250_000_000)
        ->and($priced->lines[0]->isValid)->toBeTrue()
        ->and($priced->subtotal)->toBe(550_000_000);
});

it('prices a roll line exactly like RollPricing at the Retail level', function () {
    [$product, $rollType] = pricedLabelProduct();
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->for($product)->roll($rollType)->create(['qty' => 3]);

    $line = app(CartPricer::class)->price($cart)->lines[0];
    $expected = RollPricing::line($product, $rollType, 3, null, PriceLevel::Retail);

    expect($line->isRollLine())->toBeTrue()
        ->and($line->unitPrice)->toBe($expected->rollPrice)
        ->and($line->total)->toBe($expected->total)
        // 12 soʻm × 1000 labels × 3 rolls.
        ->and($line->total)->toBe(12 * 100 * 1000 * 3)
        ->and($line->rollLine->pricePerLabel)->toBe(1200);
});

it('never uses the Dealer or Wholesale price', function () {
    [$labels, $rollType] = pricedLabelProduct();
    $scanner = Product::factory()->create(['price' => 100_000, 'price_dealer' => 1, 'price_wholesale' => 1]);
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->for($scanner)->create(['qty' => 1]);
    CartItem::factory()->for($cart)->for($labels)->roll($rollType)->create(['qty' => 2]);

    $priced = app(CartPricer::class)->price($cart);

    expect($priced->subtotal)->toBe(100_000 + 1200 * 1000 * 2)
        ->and($priced->lines[0]->unitPrice)->not->toBe($scanner->price_dealer);
});

it('flags lines that can no longer be bought and leaves them out of the subtotal', function (Closure $break, bool $quotable) {
    [$labels, $rollType] = pricedLabelProduct();
    $cart = Cart::factory()->create();
    $ok = Product::factory()->create(['price' => 50_000]);
    CartItem::factory()->for($cart)->for($ok)->create();
    CartItem::factory()->for($cart)->for($labels)->roll($rollType)->create(['qty' => 2]);

    $break($labels, $rollType);

    $priced = app(CartPricer::class)->price($cart);
    $line = $priced->lines[1];

    expect($line->isValid)->toBeFalse()
        ->and($line->problem)->toBe(PricedLine::PROBLEM_UNAVAILABLE)
        ->and($line->total)->toBeNull()
        ->and($line->quotable)->toBe($quotable)
        ->and($priced->hasInvalidLines())->toBeTrue()
        ->and($priced->subtotal)->toBe(50_000);
})->with([
    'roll type detached' => [fn (Product $product, RollType $rollType) => $product->rollTypes()->detach($rollType->id), false],
    'roll type inactive' => [fn (Product $product, RollType $rollType) => $rollType->update(['is_active' => false]), false],
    'product inactive' => [fn (Product $product) => $product->update(['is_active' => false]), false],
    'product deleted' => [fn (Product $product) => $product->delete(), false],
    'quote only now' => [fn (Product $product) => $product->update(['sale_mode' => SaleMode::Quote]), true],
    'price removed' => [fn (Product $product) => $product->update(['price' => null]), true],
    'out of stock' => [fn (Product $product) => $product->update(['stock_status' => StockStatus::OutOfStock]), true],
]);

it('flags a line below a raised min_qty as a quantity problem', function () {
    $product = Product::factory()->create(['price' => 10_000, 'min_qty' => 1]);
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->for($product)->create(['qty' => 2]);
    $product->update(['min_qty' => 5]);

    $line = app(CartPricer::class)->price($cart)->lines[0];

    expect($line->problem)->toBe(PricedLine::PROBLEM_QUANTITY)
        ->and($line->unitPrice)->toBe(10_000)
        ->and($line->total)->toBeNull()
        ->and($line->minQty)->toBe(5);
});

it('changes the fingerprint when a price, quantity or availability changes', function () {
    $product = Product::factory()->create(['price' => 10_000]);
    $cart = Cart::factory()->create();
    $item = CartItem::factory()->for($cart)->for($product)->create(['qty' => 2]);
    $pricer = app(CartPricer::class);

    $first = $pricer->price($cart)->fingerprint;
    expect($pricer->price($cart)->fingerprint)->toBe($first)
        ->and($first)->toMatch('/^[0-9a-f]{64}$/');

    $product->update(['price' => 11_000]);
    $second = $pricer->price($cart)->fingerprint;
    $item->update(['qty' => 3]);
    $third = $pricer->price($cart)->fingerprint;
    $product->update(['stock_status' => StockStatus::OutOfStock]);
    $fourth = $pricer->price($cart)->fingerprint;

    expect(array_unique([$first, $second, $third, $fourth]))->toHaveCount(4);
});

it('notes on-order lines', function () {
    $product = Product::factory()->create(['stock_status' => StockStatus::OnOrder]);
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->for($product)->create();

    $line = app(CartPricer::class)->price($cart)->lines[0];

    expect($line->isValid)->toBeTrue()
        ->and($line->isOnOrder())->toBeTrue();
});
