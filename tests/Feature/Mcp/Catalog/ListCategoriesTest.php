<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Catalog\ListCategories;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    mcpHolder($this->plain = fakeMcpTokenValue());
});

it('lists the tree depth-first with counts', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    Category::factory()->create();
    Product::factory()->count(2)->inCategory($child)->create();
    Product::factory()->inCategory($child)->create()->delete();

    $items = mcpCallTool($this->plain, 'list_categories')->assertOk()->json('result.structuredContent.items');

    expect(array_column($items, 'id')[0])->toBe($root->id)
        ->and($items[1]['id'])->toBe($child->id)
        ->and($items[1]['depth'])->toBe(2)
        ->and($items[1]['product_count'])->toBe(2)
        ->and($items[0])->not->toHaveKey('attributes')
        ->and($items[1]['name'])->toHaveKeys(['uz', 'ru', 'en']);
});

it('returns assigned attributes with at most 50 options', function () {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->select(array_map(fn (int $i): string => "Opt {$i}", range(1, 60)))->create();
    $category->attributes()->attach($attribute->id, ['is_required' => true, 'is_filterable' => false, 'sort' => 1]);

    $item = mcpCallTool($this->plain, 'list_categories', ['with_attributes' => true])->assertOk()->json('result.structuredContent.items.0');
    $row = $item['attributes'][0];

    expect($row['is_required'])->toBeTrue()
        ->and($row['is_filterable'])->toBeFalse()
        ->and($row['options_total'])->toBe(60)
        ->and($row['options'])->toHaveCount(50);
});

it('is allowed for catalog viewers and keeps the response under the cap', function () {
    Category::factory()->count(5)->create();

    AdminServer::actingAs(staffUser(Role::SalesManager))->tool(ListCategories::class)->assertOk();

    $raw = mcpCallTool($this->plain, 'list_categories', ['with_attributes' => true])->getContent();

    expect(strlen($raw))->toBeLessThan(70000);
});
