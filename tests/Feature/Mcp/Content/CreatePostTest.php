<?php

use App\Enums\McpCallStatus;
use App\Enums\NewsStatus;
use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\CreatePost;
use App\Mcp\Tools\PosonTool;
use App\Models\McpCall;
use App\Models\NewsPost;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
});

/**
 * Valid create_post arguments (a title and a body in uz), with overrides.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function postArgs(array $over = []): array
{
    return [
        'title' => ['uz' => 'Yangi skaner modeli'],
        'body' => ['uz' => '<p>Skaner haqida matn.</p>'],
        ...$over,
    ];
}

/**
 * Post alerts only (the HTTP path also sends the token's first-use alert).
 *
 * @return list<string>
 */
function postAlertTexts(): array
{
    return array_values(array_filter(mcpAlertTexts(), fn (string $text): bool => str_contains($text, 'poson.test/admin/')));
}

function createPost(string $plain, array $args)
{
    return mcpCallTool($plain, 'create_post', $args);
}

it('creates a draft by default, with the user as author and no alert', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs(['kind' => 'article', 'meta_title' => ['uz' => 'Meta']]))->assertOk()->json('result.structuredContent');

    $post = NewsPost::query()->sole();
    expect($content['status'])->toBe('draft')->and($content['state'])->toBe('draft')->and($content['public_url'])->toBeNull()
        ->and($content['admin_url'])->toBe('https://poson.test/admin/articles/'.$post->id.'/edit')
        ->and($content['warnings'])->toBe([])->and($content['issues'])->toBeArray()
        ->and($post->kind->value)->toBe('article')->and($post->author_id)->toBe($ceo->id)
        ->and(McpCall::query()->sole())->is_write->toBeTrue()->is_publish->toBeFalse()->before->toBeNull()
        ->subject_id->toBe($post->id)->subject_type->toBe('news_post')
        ->and(postAlertTexts())->toBe([]);
});

it('forces a draft with a warning when publish is not confirmed', function (array $extra) {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs(['publish' => true, ...$extra]))->assertOk()->json('result.structuredContent');

    expect($content['status'])->toBe('draft')->and($content['warnings'])->toBe([['code' => 'publish_needs_confirm']])
        ->and(McpCall::query()->sole()->is_publish)->toBeFalse()
        ->and(postAlertTexts())->toBe([]);
})->with([[[]], [['confirm_publish' => false]]]);

it('publishes with publish and confirm_publish, logs a publish and sends exactly one alert', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs(['publish' => true, 'confirm_publish' => true, 'title' => ['uz' => 'Chop etilgan <i>post</i>']]))->assertOk()->json('result.structuredContent');

    $post = NewsPost::query()->sole();
    expect($content['status'])->toBe('published')->and($content['state'])->toBe('published')->and($content['scheduled'])->toBeFalse()
        ->and($content['public_url'])->toBe('https://poson.test/uz/news/'.$post->slug)
        ->and(McpCall::query()->sole()->is_publish)->toBeTrue();

    $alerts = postAlertTexts();
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toContain('https://poson.test/uz/news/'.$post->slug);
});

it('schedules a publication up to 90 days ahead', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs(['publish' => true, 'confirm_publish' => true, 'published_at' => now()->addDays(10)->toIso8601String()]))
        ->assertOk()->json('result.structuredContent');

    expect($content['status'])->toBe('published')->and($content['scheduled'])->toBeTrue()->and($content['state'])->toBe('scheduled')
        ->and($content['public_url'])->toBeNull()
        ->and(McpCall::query()->sole()->is_publish)->toBeTrue()
        ->and(postAlertTexts())->toHaveCount(1);
});

it('rejects a past, too far or unparsable published_at, and one without publish', function (array $extra) {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(createPost($plain, postArgs($extra))->json('result.isError'))->toBeTrue()
        ->and(NewsPost::query()->count())->toBe(0);
})->with([
    '+91 days' => fn () => [['publish' => true, 'confirm_publish' => true, 'published_at' => now()->addDays(91)->toIso8601String()]],
    'past' => fn () => [['publish' => true, 'confirm_publish' => true, 'published_at' => now()->subDay()->toIso8601String()]],
    'garbage' => [['publish' => true, 'confirm_publish' => true, 'published_at' => 'not a date']],
    'no publish' => fn () => [['published_at' => now()->addDay()->toIso8601String()]],
]);

it('falls back to a draft with the hosts for every external link vector (M3-S6)', function (string $href) {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs([
        'publish' => true, 'confirm_publish' => true,
        'body' => ['uz' => '<p>Matn <a href="'.$href.'">havola</a></p>'],
    ]))->assertOk()->json('result.structuredContent');

    expect($content['status'])->toBe('draft')->and($content['warnings'][0]['code'])->toBe('external_links')
        ->and(count($content['warnings'][0]['hosts']))->toBeLessThanOrEqual(5)
        ->and(McpCall::query()->sole()->is_publish)->toBeFalse()
        ->and(postAlertTexts())->toBe([]);
})->with([
    'upper scheme' => 'HTTP://x.example/a',
    'protocol relative' => '//x.example/a',
    'backslashes' => '\\\\x.example/a',
    'suffix lookalike' => 'https://evil.com.poson.uz/a',
    'prefix lookalike' => 'https://poson.test.evil.example/a',
    'entity encoded' => '&#104;ttps://evil.example/a',
    'mailto' => 'mailto:someone@example.com',
    'javascript' => 'javascript:alert(1)',
]);

it('still publishes with site-relative and own-host links including the www twin', function (string $href) {
    mcpHolder($plain = fakeMcpTokenValue());

    $content = createPost($plain, postArgs([
        'publish' => true, 'confirm_publish' => true,
        'body' => ['uz' => '<p>Matn <a href="'.$href.'">havola</a></p>'],
    ]))->assertOk()->json('result.structuredContent');

    expect($content['status'])->toBe('published')->and($content['warnings'])->toBe([]);
})->with(['relative' => '/uz/catalog', 'own' => 'https://poson.test/uz/catalog', 'www twin' => 'https://www.poson.test/uz/catalog']);

it('counts the 6th publish attempt even when earlier ones fell back to a draft (429)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $external = postArgs(['publish' => true, 'confirm_publish' => true, 'body' => ['uz' => '<p><a href="https://x.example">x</a></p>']]);

    foreach (range(1, PosonTool::PUBLISH_LIMIT_PER_DAY) as $i) {
        createPost($plain, $external)->assertOk();
    }

    $sixth = createPost($plain, postArgs(['publish' => true, 'confirm_publish' => true]));

    expect($sixth->json('result.isError'))->toBeTrue()->and($sixth->getContent())->toContain('Retry after')
        ->and(NewsPost::query()->count())->toBe(5)
        ->and(McpCall::query()->where('status', McpCallStatus::RateLimited)->count())->toBe(1)
        ->and(McpCall::query()->where('is_publish', true)->count())->toBe(0);
});

it('rejects arguments outside the contract without echoing them (M3-S2)', function (string $key) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = createPost($plain, postArgs([$key => 'SENTINEL-VALUE-9']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(NewsPost::query()->count())->toBe(0);
})->with(['id', 'status', 'slug', 'author_id', 'sort', 'faqable_type', 'template', 'is_system', 'needs_review', 'is_active', 'price', 'price_dealer', 'price_wholesale', 'pricing_unit', 'ikpu_code', 'package_code', 'roll_type_ids', 'is_featured', 'badge', 'stock_status', 'images', 'documents']);

it('rejects unknown locales and non-string texts', function (array $args) {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(createPost($plain, $args)->json('result.isError'))->toBeTrue();
})->with([
    'locale' => [['title' => ['uz' => 'A', 'de' => 'B']]],
    'no uz' => [['title' => ['ru' => 'A']]],
    'array text' => [['title' => ['uz' => ['x']]]],
    'brand rule' => [['title' => ['uz' => 'Eng arzon skanerlar']]],
    'too many products' => [['title' => ['uz' => 'A'], 'product_ids' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21]]],
]);

it('never touches an existing record and creates a second row for the same title (M3-S1, S5)', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());
    $existing = NewsPost::factory()->create(['title' => ['uz' => 'Yangi skaner modeli']]);
    $before = NewsPost::query()->find($existing->id)->getAttributes();
    $this->travel(2)->minutes();

    $first = createPost($plain, postArgs())->assertOk()->json('result.structuredContent.id');
    $second = createPost($plain, postArgs())->assertOk()->json('result.structuredContent.id');

    $slugs = NewsPost::query()->pluck('slug');
    expect(NewsPost::query()->count())->toBe(3)->and($slugs->unique())->toHaveCount(3)
        ->and(NewsPost::query()->find($existing->id)->getAttributes())->toBe($before)
        ->and($first)->not->toBe($second)
        ->and(NewsPost::query()->find($first)->slug)->not->toBe($existing->slug);
});

it('does not touch an existing post as Admin either, whatever the id in the title or slug text', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $existing = NewsPost::factory()->create(['slug' => 'mavjud-post']);
    $before = NewsPost::query()->find($existing->id)->getAttributes();

    createPost($plain, postArgs(['title' => ['uz' => 'mavjud-post']]))->assertOk();
    expect(createPost($plain, postArgs(['slug' => 'mavjud-post', 'id' => $existing->id]))->json('result.isError'))->toBeTrue();

    expect(NewsPost::query()->find($existing->id)->getAttributes())->toBe($before)->and(NewsPost::query()->count())->toBe(2);
});

it('is registered for Admin and Content manager only, not read-only (M3-S16)', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));

    $allowed ? $server->tool(CreatePost::class, postArgs())->assertOk() : $server->tool(CreatePost::class, postArgs())->assertNotRegistered();
    expect((new ReflectionClass(CreatePost::class))->getAttributes(IsReadOnly::class))->toBeEmpty()
        ->and(app(CreatePost::class)->description())->toContain('you cannot edit existing items');
})->with([[Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, false]]);

it('keeps stored XSS payloads inert in the public page, meta and JSON-LD (M3-S10)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $payload = '</script><script>alert(7)</script>';
    $body = '<p>Salom</p><script>alert(1)</script><img src=x onerror=alert(2)><svg onload=alert(3)></svg><iframe src="https://x.example"></iframe>'
        .'<a href="javascript:alert(4)">j</a><a href="data:text/html;base64,PHNjcmlwdD4=">d</a><p onclick="alert(5)">q</p>';

    // The javascript:/data: links force a draft; staff publishing it later must still render it inert.
    createPost($plain, postArgs([
        'publish' => true, 'confirm_publish' => true,
        'title' => ['uz' => 'Sarlavha '.$payload],
        'excerpt' => ['uz' => 'Qisqa '.$payload],
        'meta_title' => ['uz' => 'Meta '.$payload],
        'meta_description' => ['uz' => 'Tavsif '.$payload],
        'body' => ['uz' => $body],
    ]))->assertOk();

    $post = NewsPost::query()->sole();
    expect($post->status)->toBe(NewsStatus::Draft);
    $post->update(['status' => NewsStatus::Published, 'published_at' => now()->subMinute()]);

    $html = $this->get('/uz/news/'.$post->slug)->assertOk()->getContent();

    expect($html)->not->toContain('alert(1)')->not->toContain('alert(2)')->not->toContain('alert(3)')->not->toContain('alert(4)')
        ->not->toContain('alert(5)')->not->toContain('onerror')->not->toContain('onload')->not->toContain('<iframe')
        ->not->toContain('javascript:')->not->toContain('data:text/html')->not->toContain('<svg onload')
        ->not->toContain('<script>alert(7)');
    expect(fn () => jsonLdBlocks($html))->not->toThrow(JsonException::class);
    expect(jsonLdBlocks($html))->not->toBeEmpty();
});
