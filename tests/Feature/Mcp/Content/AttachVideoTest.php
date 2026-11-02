<?php

use App\Enums\McpCallStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Jobs\FetchVideoDetails;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Content\AttachVideo;
use App\Models\Brand;
use App\Models\Category;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function videoArgs(string $owner, array $over = []): array
{
    return ['owner' => $owner, 'url' => 'https://youtu.be/abcdefghijk', ...$over];
}

it('attaches an inactive video even for the Admin, with no outbound HTTP', function (string $kind) {
    mcpHolder($plain = fakeMcpTokenValue());
    $owner = match ($kind) {
        'product' => Product::factory()->create(),
        'category' => Category::factory()->create(),
        'brand' => Brand::factory()->create(),
        'post' => NewsPost::factory()->create(),
    };

    $content = mcpCallTool($plain, 'attach_video', videoArgs($kind.':'.$owner->id, ['title' => ['uz' => 'Sozlash']]))->assertOk()->json('result.structuredContent');

    $video = Video::query()->sole();
    expect($content)->toMatchArray(['id' => $video->id, 'owner' => $kind.':'.$owner->id, 'youtube_id' => 'abcdefghijk', 'is_active' => false])
        ->and($content['admin_url'])->toStartWith('https://')
        ->and($video->is_active)->toBeFalse()
        ->and($video->getTranslation('title', 'uz'))->toBe('Sozlash')
        ->and(McpCall::query()->sole())->is_write->toBeTrue()->is_publish->toBeFalse()->before->toBeNull()
        ->subject_type->toBe('video')->subject_id->toBe($video->id);

    Http::assertNothingSent();
    Queue::assertPushed(FetchVideoDetails::class, 1);
})->with(['product', 'category', 'brand', 'post']);

it('rejects arguments outside the contract without echoing them', function (string $key) {
    mcpHolder($plain = fakeMcpTokenValue());
    $product = Product::factory()->create();

    $response = mcpCallTool($plain, 'attach_video', videoArgs('product:'.$product->id, [$key => 'SENTINEL-VALUE-9']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(Video::query()->count())->toBe(0);
})->with(['id', 'is_active', 'sort', 'youtube_id', 'videoable_type', 'videoable_id', 'fetch_status', 'status', 'slug', 'source_title', 'thumbnail_at']);

it('answers the same for a missing, trashed or forbidden owner and checks access before duplicates', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $hardware = Product::factory()->create();
    $trashed = Product::factory()->create();
    $trashed->delete();
    $service = Product::factory()->create(['type' => ProductType::Service]);
    Video::factory()->owner($hardware)->create(['youtube_id' => 'abcdefghijk']);

    foreach (['product:999999', 'product:'.$trashed->id, 'product:'.$hardware->id, 'category:'.Category::factory()->create()->id] as $owner) {
        AdminServer::actingAs($engineer)->tool(AttachVideo::class, videoArgs($owner))->assertHasErrors(['Video owner not found.']);
    }

    AdminServer::actingAs($engineer)->tool(AttachVideo::class, videoArgs('product:'.$service->id))->assertOk();
    expect(Video::query()->count())->toBe(2);
});

it('rejects malformed owners', function (string $owner) {
    mcpHolder($plain = fakeMcpTokenValue());

    expect(mcpCallTool($plain, 'attach_video', videoArgs($owner))->json('result.isError'))->toBeTrue()
        ->and(Video::query()->count())->toBe(0);
})->with(['product:1;category:2', 'product:1 ', "product:1\n", 'PRODUCT:1', 'product:', 'order:5', 'page:1', 'general', '']);

it('reports invalid links, duplicates and the cap without echoing input', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $product = Product::factory()->create();
    $owner = 'product:'.$product->id;

    $bad = mcpCallTool($plain, 'attach_video', videoArgs($owner, ['url' => 'https://evil.example/<script>']));
    expect($bad->json('result.isError'))->toBeTrue()->and($bad->getContent())->not->toContain('evil.example');

    mcpCallTool($plain, 'attach_video', videoArgs($owner))->assertOk();
    $duplicate = mcpCallTool($plain, 'attach_video', videoArgs($owner));
    expect($duplicate->json('result.isError'))->toBeTrue()->and($duplicate->getContent())->not->toContain('abcdefghijk');

    foreach (range(1, 19) as $i) {
        Video::factory()->owner($product)->create(['sort' => $i + 1]);
    }
    $limit = mcpCallTool($plain, 'attach_video', videoArgs($owner, ['url' => 'bbbbbbbbbbb']));
    expect($limit->json('result.isError'))->toBeTrue()->and(Video::query()->count())->toBe(20);
});

it('applies the brand rule to titles and validates the date', function (array $over) {
    mcpHolder($plain = fakeMcpTokenValue());
    $product = Product::factory()->create();

    expect(mcpCallTool($plain, 'attach_video', videoArgs('product:'.$product->id, $over))->json('result.isError'))->toBeTrue()
        ->and(Video::query()->count())->toBe(0);
})->with([
    'discount' => [['title' => ['uz' => 'Chegirma bilan printer']]],
    'long' => [['title' => ['uz' => str_repeat('a', 201)]]],
    'extra locale' => [['title' => ['de' => 'x']]],
    'future' => [['published_on' => '2999-01-01']],
    'old' => [['published_on' => '2001-01-01']],
    'format' => [['published_on' => '01.05.2024']],
    'url array' => [['url' => ['abcdefghijk']]],
    'url long' => [['url' => str_repeat('a', 501)]],
]);

it('stops the 31st write in an hour', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $product = Product::factory()->create();
    RateLimiter::clear('mcp-write:'.User::query()->first()->id);

    foreach (range(1, 30) as $i) {
        mcpCallTool($plain, 'attach_video', videoArgs('product:'.$product->id, ['url' => sprintf('video%06d', $i)]))->assertOk();
    }

    $response = mcpCallTool($plain, 'attach_video', videoArgs('product:'.$product->id, ['url' => 'zzzzzzzzzzz']));
    expect($response->getContent())->toContain('Rate limit reached')
        ->and(McpCall::query()->where('status', McpCallStatus::RateLimited)->count())->toBe(1);
});

it('is not read-only and its description says it cannot edit videos', function () {
    expect((new ReflectionClass(AttachVideo::class))->getAttributes(IsReadOnly::class))->toBeEmpty()
        ->and(app(AttachVideo::class)->description())->toContain('inactive')->toContain('cannot edit');
});

it('is registered for Admin, Content manager and Service engineer only', function (Role $role, bool $allowed) {
    $server = AdminServer::actingAs(staffUser($role));
    $args = videoArgs('product:'.Product::factory()->create(['type' => ProductType::Service])->id);

    $allowed ? $server->tool(AttachVideo::class, $args)->assertDontSee('not found.') : $server->tool(AttachVideo::class, $args)->assertNotRegistered();
})->with([[Role::Admin, true], [Role::ContentManager, true], [Role::SalesManager, false], [Role::ServiceEngineer, true]]);
