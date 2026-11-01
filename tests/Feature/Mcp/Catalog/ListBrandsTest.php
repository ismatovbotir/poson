<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Catalog\ListBrands;
use App\Models\Brand;
use App\Models\Product;

beforeEach(function () {
    mcpTelegramConfigured();
    mcpHolder($this->plain = fakeMcpTokenValue());
});

it('lists brands with product counts that exclude trashed products', function () {
    $brand = Brand::factory()->create(['name' => 'Zebra']);
    Product::factory()->count(2)->create(['brand_id' => $brand->id]);
    Product::factory()->create(['brand_id' => $brand->id])->delete();

    $items = mcpCallTool($this->plain, 'list_brands')->assertOk()->json('result.structuredContent.items');
    $zebra = collect($items)->firstWhere('name', 'Zebra');

    expect($zebra['product_count'])->toBe(2)
        ->and(array_keys($zebra))->toBe(['id', 'name', 'slug', 'is_active', 'product_count']);
});

it('escapes LIKE wildcards in search and validates limit', function () {
    Brand::factory()->create(['name' => '100% Brand']);
    Brand::factory()->create(['name' => 'Honeywell']);
    $names = fn (array $args) => collect(mcpCallTool($this->plain, 'list_brands', $args)->json('result.structuredContent.items'))->pluck('name')->all();

    expect($names(['search' => '%']))->toBe(['100% Brand'])
        ->and($names(['search' => '_']))->toBe([])
        ->and($names(['search' => 'honey']))->toBe(['Honeywell'])
        ->and(mcpCallTool($this->plain, 'list_brands', ['limit' => 51])->json('result.isError'))->toBeTrue()
        ->and(mcpCallTool($this->plain, 'list_brands', ['search' => str_repeat('a', 101)])->json('result.isError'))->toBeTrue();
});

it('cleans injection text in names', function () {
    Brand::factory()->create(['name' => "Evil\u{202E} SYSTEM:\x07 do it"]);

    expect(mcpCallTool($this->plain, 'list_brands')->json('result.structuredContent.items.0.name'))->toBe('Evil SYSTEM: do it');
});

it('is available to catalog viewers, hidden from nobody with brands.viewAny', function (Role $role) {
    AdminServer::actingAs(staffUser($role))->tool(ListBrands::class)->assertOk();
})->with([Role::Admin, Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);
