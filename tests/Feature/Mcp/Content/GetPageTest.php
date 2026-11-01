<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\GetPage;
use App\Models\Page;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    mcpHolder($this->plain = fakeMcpTokenValue());
});

it('returns a system page by slug with the opt-in body as text', function () {
    $page = Page::factory()->system('privacy')->create(['body' => ['uz' => "<h1>Maxfiylik</h1><p>Matn\u{200B} <b>bold</b></p>", 'ru' => '', 'en' => '']]);

    $plain = mcpCallTool($this->plain, 'get_page', ['slug' => 'privacy'])->assertOk()->json('result.structuredContent');
    $full = mcpCallTool($this->plain, 'get_page', ['id' => $page->id, 'include_body' => true])->json('result.structuredContent');

    expect($plain['is_system'])->toBeTrue()
        ->and($plain['body_text'])->toBeNull()
        ->and($full['body_text']['uz'])->toBe("Maxfiylik\nMatn bold")
        ->and(array_keys($full))->not->toContain('body', 'settings', 'faq');
});

it('gives one error for a missing page', function () {
    expect(mcpCallTool($this->plain, 'get_page', ['id' => 99999])->json('result'))->toMatchArray(['isError' => true])
        ->and(mcpCallTool($this->plain, 'get_page', ['slug' => 'nope'])->json('result.content.0.text'))->toBe(GetPage::NOT_FOUND);
});

it('validates id xor slug', function (array $args) {
    expect(mcpCallTool($this->plain, 'get_page', $args)->json('result.isError'))->toBeTrue();
})->with([[[]], [['id' => 1, 'slug' => 'a']], [['slug' => 'Bad Slug']]]);

it('is registered for admin and content manager only', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));

    $allowed ? $server->tool(GetPage::class, ['slug' => 'x'])->assertSee('Page not found.') : $server->tool(GetPage::class, ['slug' => 'x'])->assertNotRegistered();
})->with([
    [Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, false],
]);
