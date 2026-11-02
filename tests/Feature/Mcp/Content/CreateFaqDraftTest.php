<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\CreateFaqDraft;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\McpCall;
use App\Models\Page;
use App\Models\Product;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function faqArgs(array $over = []): array
{
    return ['question' => ['uz' => 'Kafolat qancha?'], 'answer' => ['uz' => 'Rasmiy kafolat 12 oy.'], ...$over];
}

it('creates an inactive general FAQ item without sending is_active (M3-S3)', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = mcpCallTool($plain, 'create_faq_draft', faqArgs())->assertOk()->json('result.structuredContent');

    $item = FaqItem::query()->sole();
    expect($content)->toBe(['id' => $item->id, 'owner' => 'general', 'is_active' => false])
        ->and($item->is_active)->toBeFalse()->and($item->isGeneral())->toBeTrue()
        ->and(McpCall::query()->sole())->is_write->toBeTrue()->is_publish->toBeFalse()->before->toBeNull()
        ->subject_type->toBe('faq_item')->subject_id->toBe($item->id);
});

it('creates inactive items for a category, a product and a page owner', function (string $kind) {
    mcpHolder($plain = fakeMcpTokenValue());
    $owner = match ($kind) {
        'category' => Category::factory()->create(),
        'product' => Product::factory()->create(),
        'page' => Page::factory()->create(),
    };

    mcpCallTool($plain, 'create_faq_draft', faqArgs(['owner' => $kind.':'.$owner->id]))->assertOk();

    $item = FaqItem::query()->sole();
    expect($item->is_active)->toBeFalse()->and($item->faqable_id)->toBe($owner->id)->and($item->faqable_type)->toBe($kind);
})->with(['category', 'product', 'page']);

it('rejects malformed owners and unknown owners with the same kind of error', function (string $owner) {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(mcpCallTool($plain, 'create_faq_draft', faqArgs(['owner' => $owner]))->json('result.isError'))->toBeTrue()
        ->and(FaqItem::query()->count())->toBe(0);
})->with(['product:1;category:2', 'product:1 ', 'PRODUCT:1', 'product:', 'order:5', 'product:99999', 'page:99999', "general\n"]);

it('rejects arguments outside the contract without echoing them (M3-S2)', function (string $key) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'create_faq_draft', faqArgs([$key => 'SENTINEL-VALUE-9']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(FaqItem::query()->count())->toBe(0);
})->with(['id', 'status', 'slug', 'author_id', 'sort', 'faqable_type', 'faqable_id', 'template', 'is_system', 'needs_review', 'is_active', 'price', 'price_dealer', 'price_wholesale', 'pricing_unit', 'ikpu_code', 'package_code', 'roll_type_ids', 'is_featured', 'badge', 'stock_status', 'images', 'documents']);

it('needs question.uz and answer.uz and applies the brand rule', function (array $args) {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(mcpCallTool($plain, 'create_faq_draft', $args)->json('result.isError'))->toBeTrue();
})->with([
    'no answer' => [['question' => ['uz' => 'Q']]],
    'ru only' => [['question' => ['ru' => 'Q'], 'answer' => ['uz' => 'A']]],
    'cheap' => [['question' => ['uz' => 'Q'], 'answer' => ['uz' => 'Eng arzon narx']]],
]);

it('never changes an existing item, even one with the same text (M3-S1)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $existing = FaqItem::factory()->create(['question' => ['uz' => 'Kafolat qancha?'], 'faqable_type' => null, 'faqable_id' => null]);
    $before = FaqItem::query()->find($existing->id)->getAttributes();

    mcpCallTool($plain, 'create_faq_draft', faqArgs())->assertOk();
    expect(mcpCallTool($plain, 'create_faq_draft', faqArgs(['id' => $existing->id]))->json('result.isError'))->toBeTrue();

    expect(FaqItem::query()->find($existing->id)->getAttributes())->toBe($before)->and(FaqItem::query()->count())->toBe(2);
});

it('lets a Content manager add to pages, categories and products but not to the general FAQ', function () {
    $manager = staffUser(Role::ContentManager);
    $page = Page::factory()->create();

    AdminServer::actingAs($manager)->tool(CreateFaqDraft::class, faqArgs(['owner' => 'page:'.$page->id]))->assertOk();
    AdminServer::actingAs($manager)->tool(CreateFaqDraft::class, faqArgs())->assertHasErrors(['FAQ owner not found.']);

    expect(FaqItem::query()->count())->toBe(1)->and(FaqItem::query()->sole()->is_active)->toBeFalse();
});

it('is registered per role and is not read-only (M3-S16)', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));
    $args = faqArgs(['owner' => 'page:'.Page::factory()->create()->id]);

    $allowed ? $server->tool(CreateFaqDraft::class, $args)->assertOk() : $server->tool(CreateFaqDraft::class, $args)->assertNotRegistered();
    expect((new ReflectionClass(CreateFaqDraft::class))->getAttributes(IsReadOnly::class))->toBeEmpty()
        ->and(app(CreateFaqDraft::class)->description())->toContain('you cannot edit existing items');
})->with([[Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, false]]);
