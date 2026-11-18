<?php

use App\Enums\ProductType;
use App\Enums\RepairDeviceType;
use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ServiceableDevice;

beforeEach(fn () => CategoryTree::flush());

function productIn(string $slug, ?string $parentSlug = null, ProductType $type = ProductType::Hardware): Product
{
    $parent = $parentSlug !== null ? Category::factory()->create(['slug' => $parentSlug]) : null;
    $category = Category::factory()->create(['slug' => $slug, 'parent_id' => $parent?->id]);

    CategoryTree::flush();

    return Product::factory()->create(['category_id' => $category->id, 'type' => $type]);
}

it('maps hardware in a mapped category to a repair device type', function (string $slug, RepairDeviceType $expected) {
    expect(ServiceableDevice::for(productIn($slug)))->toBe($expected);
})->with([
    ['scanners', RepairDeviceType::Scanner],
    ['label-printers', RepairDeviceType::LabelPrinter],
    ['rfid-printers', RepairDeviceType::LabelPrinter],
    ['receipt-printers', RepairDeviceType::ReceiptPrinter],
    ['mobile-computers', RepairDeviceType::MobileComputer],
    ['rfid', RepairDeviceType::Rfid],
    ['pos', RepairDeviceType::Pos],
]);

it('walks up to an ancestor and lets the nearest mapped slug win', function () {
    expect(ServiceableDevice::for(productIn('handheld-scanners', 'scanners')))->toBe(RepairDeviceType::Scanner)
        ->and(ServiceableDevice::for(productIn('rfid-printers', 'rfid')))->toBe(RepairDeviceType::LabelPrinter);
});

it('excludes excluded categories, nearest first', function (string $slug, string $parent) {
    expect(ServiceableDevice::for(productIn($slug, $parent)))->toBeNull();
})->with([
    ['cash-drawers', 'pos'],
    ['rfid-antennas', 'rfid'],
    ['rfid-tags', 'rfid'],
    ['accessories', 'accessories-parts'],
    ['spare-parts', 'accessories-parts'],
]);

it('is null for consumables, software, services and unmapped categories', function (ProductType $type) {
    expect(ServiceableDevice::for(productIn('scanners', null, $type)))->toBeNull();
})->with([ProductType::Consumable, ProductType::Software, ProductType::Service]);

it('drops unknown config values', function () {
    config(['site.service_device_categories' => ['scanners' => 'toaster']]);

    expect(ServiceableDevice::for(productIn('scanners')))->toBeNull()
        ->and(ServiceableDevice::for(productIn('misc')))->toBeNull();
});
