<?php

use App\Data\Site\ProductCard;
use App\Enums\ProductBadge;
use App\Enums\SaleMode;
use App\Enums\StockStatus;

function productCard(?int $price = 100_000, SaleMode $saleMode = SaleMode::Cart, ?ProductBadge $badge = null): ProductCard
{
    return new ProductCard(
        name: 'Zebra DS2208',
        slug: 'zebra-ds2208',
        sku: 'ZB-DS2208',
        specs: '2D · USB',
        price: $price,
        stockStatus: StockStatus::InStock,
        saleMode: $saleMode,
        image: '/assets/site/products/scanner.svg',
        url: '#',
        badge: $badge,
        brand: 'Zebra',
    );
}

it('has only the hit and new badges', function () {
    expect(array_column(ProductBadge::cases(), 'value'))->toBe(['hit', 'new'])
        ->and(productCard(badge: ProductBadge::Hit)->badge)->toBe(ProductBadge::Hit)
        ->and(ProductBadge::tryFrom('sale'))->toBeNull();
});

it('rejects a negative price', function () {
    productCard(-1);
})->throws(InvalidArgumentException::class);
