<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\ListPages;
use App\Models\Page;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
});

it('lists pages without bodies', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Page::factory()->system('about')->create();
    Page::factory()->inactive()->create(['body' => ['uz' => 'BODY-SENTINEL', 'ru' => '', 'en' => '']]);

    $response = mcpCallTool($plain, 'list_pages')->assertOk();
    $content = $response->json('result.structuredContent');

    expect($content['items'])->toHaveCount(2)
        ->and($content['items'][0])->toHaveKeys(['id', 'slug', 'template', 'is_system', 'is_active', 'needs_review', 'title'])
        ->and($response->getContent())->not->toContain('BODY-SENTINEL');
});

it('pages with per_page and has_more', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Page::factory()->count(25)->create();

    $content = mcpCallTool($plain, 'list_pages', ['per_page' => 10])->assertOk()->json('result.structuredContent');

    expect($content['items'])->toHaveCount(10)
        ->and($content['has_more'])->toBeTrue()
        ->and($content['page'])->toBe(1)
        ->and($content['per_page'])->toBe(10);
});

it('rejects a per_page above 50', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(mcpCallTool($plain, 'list_pages', ['per_page' => 51])->json('result.isError'))->toBeTrue();
});

it('is registered for admin and content manager only', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));

    $allowed ? $server->tool(ListPages::class)->assertOk() : $server->tool(ListPages::class)->assertNotRegistered();
})->with([
    [Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, false],
]);
