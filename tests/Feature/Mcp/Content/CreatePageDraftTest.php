<?php

use App\Enums\PageTemplate;
use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\CreatePageDraft;
use App\Models\McpCall;
use App\Models\Page;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function pageArgs(array $over = []): array
{
    return ['title' => ['uz' => 'Servis shartlari'], 'body' => ['uz' => '<p>Matn</p>'], ...$over];
}

it('creates an inactive, needs-review, non-system page with the default template (M3-S3)', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = mcpCallTool($plain, 'create_page_draft', pageArgs())->assertOk()->json('result.structuredContent');

    $page = Page::query()->sole();
    expect($content)->toBe(['id' => $page->id, 'slug' => $page->slug, 'is_active' => false, 'needs_review' => true, 'admin_url' => 'https://poson.test/admin/pages/'.$page->id.'/edit'])
        ->and($page->is_active)->toBeFalse()->and($page->needs_review)->toBeTrue()->and($page->is_system)->toBeFalse()
        ->and($page->template)->toBe(PageTemplate::Default)
        ->and(McpCall::query()->sole())->is_write->toBeTrue()->before->toBeNull()->subject_id->toBe($page->id)->subject_type->toBe('page');
});

it('never reuses a system or existing slug (M3-S5)', function (string $title) {
    mcpHolder($plain = fakeMcpTokenValue());
    $system = Page::factory()->system('contacts')->create();
    $before = Page::query()->find($system->id)->getAttributes();

    $slug = mcpCallTool($plain, 'create_page_draft', pageArgs(['title' => ['uz' => $title]]))->assertOk()->json('result.structuredContent.slug');

    expect($slug)->not->toBe('contacts')->and(Page::isSystemSlug($slug))->toBeFalse()
        ->and(Page::query()->find($system->id)->getAttributes())->toBe($before)
        ->and(Page::query()->where('slug', $slug)->count())->toBe(1);
})->with(['contacts', 'Contacts', 'about', 'privacy', 'offer', 'b2b', 'delivery-payment', 'warranty-service']);

it('creates a second page for the same title and leaves the first untouched', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $first = mcpCallTool($plain, 'create_page_draft', pageArgs())->assertOk()->json('result.structuredContent');
    $before = Page::query()->find($first['id'])->getAttributes();
    $this->travel(2)->minutes();

    $second = mcpCallTool($plain, 'create_page_draft', pageArgs())->assertOk()->json('result.structuredContent');

    expect($second['id'])->not->toBe($first['id'])->and($second['slug'])->not->toBe($first['slug'])
        ->and(Page::query()->find($first['id'])->getAttributes())->toBe($before);
});

it('rejects arguments outside the contract without echoing them (M3-S2)', function (string $key) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'create_page_draft', pageArgs([$key => 'SENTINEL-VALUE-9']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(Page::query()->count())->toBe(0);
})->with(['id', 'status', 'slug', 'author_id', 'sort', 'faqable_type', 'template', 'is_system', 'needs_review', 'is_active', 'price', 'price_dealer', 'price_wholesale', 'pricing_unit', 'ikpu_code', 'package_code', 'roll_type_ids', 'is_featured', 'badge', 'stock_status', 'images', 'documents']);

it('sanitizes the body and applies the brand rule', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    mcpCallTool($plain, 'create_page_draft', pageArgs(['body' => ['uz' => '<p onclick="x()">Matn</p><script>alert(1)</script><a href="javascript:alert(2)">j</a>']]))->assertOk();
    $body = Page::query()->sole()->getTranslation('body', 'uz');

    expect($body)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:');
    expect(mcpCallTool($plain, 'create_page_draft', pageArgs(['title' => ['uz' => 'Arzon narxlar']]))->json('result.isError'))->toBeTrue()
        ->and(Page::query()->count())->toBe(1);
});

it('is registered per role and is not read-only (M3-S16)', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));

    $allowed ? $server->tool(CreatePageDraft::class, pageArgs())->assertOk() : $server->tool(CreatePageDraft::class, pageArgs())->assertNotRegistered();
    expect((new ReflectionClass(CreatePageDraft::class))->getAttributes(IsReadOnly::class))->toBeEmpty()
        ->and(app(CreatePageDraft::class)->description())->toContain('you cannot edit existing items');
})->with([[Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, false]]);
