<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\ListFaq;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Product;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    mcpHolder($this->plain = fakeMcpTokenValue());
});

it('lists the general, category, product and page FAQ', function () {
    FaqItem::factory()->create(['question' => ['uz' => 'General?', 'ru' => '', 'en' => '']]);
    FaqItem::factory()->forCategory($category = Category::factory()->create())->create();
    FaqItem::factory()->forProduct($product = Product::factory()->create())->create();
    FaqItem::factory()->owner($page = Page::factory()->create())->create();
    $call = fn (string $owner) => mcpCallTool($this->plain, 'list_faq', ['owner' => $owner])->assertOk()->json('result.structuredContent');

    expect($call('general')['items'])->toHaveCount(1)
        ->and($call('general')['items'][0]['question']['uz'])->toBe('General?')
        ->and(mcpCallTool($this->plain, 'list_faq')->json('result.structuredContent.items'))->toHaveCount(1)
        ->and($call('category:'.$category->id)['items'])->toHaveCount(1)
        ->and($call('product:'.$product->id)['items'])->toHaveCount(1)
        ->and($call('page:'.$page->id)['items'])->toHaveCount(1);
});

it('filters active and cleans and caps answers', function () {
    FaqItem::factory()->create(['answer' => ['uz' => "Line1\nLine2 <script>x</script>\u{202E}", 'ru' => '', 'en' => '']]);
    FaqItem::factory()->inactive()->create();
    FaqItem::factory()->create(['answer' => ['uz' => str_repeat('A', 3000), 'ru' => '', 'en' => '']]);

    $active = mcpCallTool($this->plain, 'list_faq', ['active' => true])->json('result.structuredContent');
    $inactive = mcpCallTool($this->plain, 'list_faq', ['active' => false])->json('result.structuredContent');

    expect($active['items'])->toHaveCount(2)
        ->and($active['items'][0]['answer']['uz'])->toBe("Line1\nLine2")
        ->and(strlen($active['items'][1]['answer']['uz']))->toBeLessThanOrEqual(2048)
        ->and($active['answers_truncated'])->toBeTrue()
        ->and($inactive['items'])->toHaveCount(1);
});

it('rejects a garbage owner and answers missing or trashed owners with one message', function (string $owner) {
    $trashed = Product::factory()->create();
    $trashed->delete();
    $result = mcpCallTool($this->plain, 'list_faq', ['owner' => str_replace('TRASHED', (string) $trashed->id, $owner)])->json('result');

    expect($result['isError'])->toBeTrue();
})->with(['category:999999', 'product:TRASHED', 'page:999999', 'general; drop', 'product:', 'products:1', 'category:12345678901', ' general']);

it('gives the same text for a missing and a trashed owner', function () {
    $trashed = Product::factory()->create();
    $trashed->delete();

    $a = mcpCallTool($this->plain, 'list_faq', ['owner' => 'product:'.$trashed->id])->json('result.content.0.text');
    $b = mcpCallTool($this->plain, 'list_faq', ['owner' => 'product:999999'])->json('result.content.0.text');

    expect($a)->toBe(ListFaq::NOT_FOUND)->and($b)->toBe($a);
});

it('restricts the general FAQ to the admin and follows the owner policy otherwise', function () {
    $category = Category::factory()->create();
    $editor = staffUser(Role::ContentManager);

    AdminServer::actingAs($editor)->tool(ListFaq::class, ['owner' => 'general'])->assertSee(ListFaq::NOT_FOUND);
    AdminServer::actingAs($editor)->tool(ListFaq::class, ['owner' => 'category:'.$category->id])->assertOk()->assertDontSee(ListFaq::NOT_FOUND);
});

it('is registered for roles that can view pages, categories or products', function (Role $role, bool $registered) {
    $server = AdminServer::actingAs(staffUser($role));

    $registered ? $server->tool(ListFaq::class)->assertSee($role === Role::Admin ? 'general' : ListFaq::NOT_FOUND) : $server->tool(ListFaq::class)->assertNotRegistered();
})->with([
    [Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, true], [Role::ServiceEngineer, true],
]);
