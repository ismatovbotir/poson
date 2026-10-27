<?php

use App\Enums\PriceLevel;
use App\Models\ApiToken;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;

it('stores per-label prices of a label SKU from the price API and prices rolls from them', function () {
    $plain = fakeApiTokenValue();
    ApiToken::factory()->withPlainText($plain)->create();
    $roll = RollType::factory()->size(1000, '40.0')->create();
    $product = Product::factory()->perLabel(12)->withRollTypes([$roll])->create(['sku' => 'LBL-DT-58X40']);

    postPrices(['items' => [priceItem('lbl-dt-58x40', 32, 28, 30)]], $plain)
        ->assertOk()
        ->assertJsonPath('items.0.status', 'updated');

    $product = $product->fresh();

    expect($product->price)->toBe(3_200)
        ->and($product->price_dealer)->toBe(2_800)
        ->and($product->price_wholesale)->toBe(3_000)
        ->and($product->isPriceSynced())->toBeTrue()
        ->and(RollPricing::line($product, $roll, 10, null, PriceLevel::Dealer)->rollPrice)->toBe(2_800_000)
        ->and(RollPricing::line($product, $roll, 10, null, PriceLevel::Retail)->total)->toBe(32_000_000);
});
