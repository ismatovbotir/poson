<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Catalog\GetProduct;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    mcpHolder($this->plain = fakeMcpTokenValue());
});

it('returns a product with specs and missing required codes, without forbidden data', function () {
    $category = Category::factory()->create();
    $dpi = Attribute::factory()->number('dpi')->create(['code' => 'dpi']);
    $iface = Attribute::factory()->select()->create(['code' => 'interface']);
    $category->attributes()->attach($dpi->id, ['is_required' => true, 'is_filterable' => true, 'sort' => 1]);
    $category->attributes()->attach($iface->id, ['is_required' => true, 'is_filterable' => true, 'sort' => 2]);
    $product = Product::factory()->withIkpu('99988877766655544', 'PKG-SENTINEL')->inCategory($category)->create([
        'price' => 500_000_00, 'price_dealer' => 411_111_100, 'price_wholesale' => 422_222_200,
    ]);
    ProductAttributeValue::query()->create(['product_id' => $product->id, 'attribute_id' => $dpi->id, 'value_number' => '203.0000', 'is_key' => true, 'sort' => 1]);

    $response = mcpCallTool($this->plain, 'get_product', ['id' => $product->id])->assertOk();
    $content = $response->json('result.structuredContent');

    expect($content['retail_price_tiyin'])->toBe(500_000_00)
        ->and($content['specs'])->toHaveCount(1)
        ->and($content['specs'][0]['code'])->toBe('dpi')
        ->and($content['specs'][0]['values'][0]['number'])->toBe('203')
        ->and($content['missing_required'])->toBe(['interface'])
        ->and($content['description_text'])->toBeNull()
        ->and(mcpFlatKeys($content))->not->toContain(...mcpForbiddenKeys())
        ->and($response->getContent())->not->toContain('411111100')->not->toContain('422222200')
        ->not->toContain('99988877766655544')->not->toContain('PKG-SENTINEL');
});

it('finds by sku and returns the description only on request, cleaned and capped', function () {
    Product::factory()->create(['sku' => 'DESC-1', 'description' => ['uz' => '<p>'.str_repeat('x', 3000).'</p><script>bad()</script>', 'ru' => '', 'en' => '']]);

    $without = mcpCallTool($this->plain, 'get_product', ['sku' => 'DESC-1'])->json('result.structuredContent');
    $with = mcpCallTool($this->plain, 'get_product', ['sku' => 'DESC-1', 'include_description' => true])->json('result.structuredContent');

    expect($without['description_text'])->toBeNull()
        ->and(strlen($with['description_text']['uz']))->toBeLessThanOrEqual(2048)
        ->and($with['description_truncated'])->toBeTrue()
        ->and($with['description_text']['uz'])->not->toContain('<', 'bad');
});

it('does not list skipped optional attributes as missing', function () {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->create(['code' => 'optional']);
    $category->attributes()->attach($attribute->id, ['is_required' => false, 'is_filterable' => false, 'sort' => 1]);
    $product = Product::factory()->inCategory($category)->create();
    $product->attributeSkips()->create(['attribute_id' => $attribute->id]);

    expect(mcpCallTool($this->plain, 'get_product', ['id' => $product->id])->json('result.structuredContent.missing_required'))->toBe([]);
});

it('gives the same error for missing and trashed products', function () {
    $trashed = Product::factory()->create();
    $trashed->delete();

    $a = mcpCallTool($this->plain, 'get_product', ['id' => $trashed->id])->json('result');
    $b = mcpCallTool($this->plain, 'get_product', ['sku' => 'NOPE-1'])->json('result');

    expect($a['isError'])->toBeTrue()
        ->and($a['content'][0]['text'])->toBe(GetProduct::NOT_FOUND)
        ->and($b['content'][0]['text'])->toBe(GetProduct::NOT_FOUND);
});

it('validates id xor sku', function (array $args) {
    expect(mcpCallTool($this->plain, 'get_product', $args)->json('result.isError'))->toBeTrue();
})->with([[[]], [['id' => 1, 'sku' => 'A']], [['sku' => 'A;DROP<b>']], [['sku' => str_repeat('A', 65)]], [['id' => -1]]]);

it('is registered for every catalog viewer including service engineers', function (Role $role) {
    $product = Product::factory()->service()->create();

    AdminServer::actingAs(staffUser($role))->tool(GetProduct::class, ['id' => $product->id])->assertOk();
})->with([Role::Admin, Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);
