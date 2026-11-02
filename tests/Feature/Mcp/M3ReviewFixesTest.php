<?php

use App\Actions\Mcp\UnpublishMcpPost;
use App\Enums\McpCallStatus;
use App\Enums\NewsStatus;
use App\Enums\Role;
use App\Livewire\Admin\Settings\McpAccess;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Support\FaqOwner;
use App\Mcp\Support\SpecInput;
use App\Mcp\Tools\Content\CreatePost;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/McpScan.php';

/**
 * create_post whose response stage fails after the (committed) publish.
 */
#[Name('create_post')]
class M3ThrowingCreatePost extends CreatePost
{
    protected function respond(NewsPost $post, bool $scheduled, array $warnings): Response|ResponseFactory
    {
        throw new RuntimeException('response failed after commit');
    }
}

class M3ThrowingServer extends AdminServer
{
    protected array $tools = [M3ThrowingCreatePost::class];
}

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function reviewPostArgs(array $over = []): array
{
    return ['title' => ['uz' => 'Yangi skaner'], 'body' => ['uz' => '<p>Matn.</p>'], ...$over];
}

it('keeps the publish row and the Unpublish button when the response fails after a committed publish (M1)', function () {
    [$ceo] = mcpHolder();

    M3ThrowingServer::actingAs($ceo)->tool(M3ThrowingCreatePost::class, reviewPostArgs(['publish' => true, 'confirm_publish' => true]))->assertHasErrors();

    $post = NewsPost::query()->sole();
    $call = McpCall::query()->sole();
    expect($post->status)->toBe(NewsStatus::Published)
        ->and($call)->status->toBe(McpCallStatus::Error)->is_publish->toBeTrue()->subject_id->toBe($post->id);

    $this->actingAs($ceo);
    confirmAdminPassword();
    Livewire::test(McpAccess::class)->set('tab', 'calls')
        ->assertSee('data-unpublish="'.$call->id.'"', false)
        ->call('unpublish', $call->id)
        ->assertDispatched('toast');

    expect($post->fresh()->status)->toBe(NewsStatus::Draft);
});

it('logs no publish when the call fails before the save or falls back to a draft', function (array $args) {
    [$ceo] = mcpHolder();

    M3ThrowingServer::actingAs($ceo)->tool(M3ThrowingCreatePost::class, reviewPostArgs($args));

    expect(McpCall::query()->sole()->is_publish)->toBeFalse();
})->with([
    'brand rule' => [['title' => ['uz' => 'Eng arzon skaner'], 'publish' => true, 'confirm_publish' => true]],
    'external link' => [['body' => ['uz' => '<p><a href="https://x.example">x</a></p>'], 'publish' => true, 'confirm_publish' => true]],
    'not confirmed' => [['publish' => true]],
]);

it('answers with empty issues when the SEO audit fails (M1)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $armed = false;
    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_contains($query->sql, '"media"')) {
            throw new RuntimeException('audit failed');
        }
    });
    NewsPost::saved(function () use (&$armed): void {
        $armed = true;
    });

    $content = mcpCallTool($plain, 'create_post', reviewPostArgs())->assertOk()->json('result.structuredContent');

    expect($content['issues'])->toBe([])->and($content['id'])->toBe(NewsPost::query()->sole()->id);
});

it('accepts publish only as a real JSON boolean (L1)', function (mixed $value) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'create_post', reviewPostArgs(['publish' => $value, 'confirm_publish' => true]));

    expect($response->json('result.isError'))->toBeTrue()->and(NewsPost::query()->count())->toBe(0)
        ->and(McpCall::query()->sole())->status->toBe(McpCallStatus::Invalid)->is_publish->toBeFalse();
})->with([1, '1', 'true', 'yes', ['x']]);

it('accepts only explicit ISO 8601 formats for published_at (L2)', function (string $value, bool $valid) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'create_post', reviewPostArgs(['publish' => true, 'confirm_publish' => true, 'published_at' => $value]));

    expect($response->json('result.isError') === true)->toBe(! $valid)->and(NewsPost::query()->count())->toBe($valid ? 1 : 0);
})->with(function () {
    $future = now('Asia/Tashkent')->addDays(5);

    return [
        'minutes' => [$future->format('Y-m-d\TH:i'), true],
        'seconds' => [$future->format('Y-m-d\TH:i:s'), true],
        'offset' => [$future->format('Y-m-d\TH:i:sP'), true],
        'offset minutes' => [$future->format('Y-m-d\TH:iP'), true],
        'tomorrow' => ['tomorrow', false],
        '+1 day' => ['+1 day', false],
        'next monday' => ['next monday', false],
        'timestamp' => ['@'.$future->timestamp, false],
        'space separator' => [$future->format('Y-m-d H:i'), false],
        'date only' => [$future->format('Y-m-d'), false],
        'overflow' => [$future->format('Y-m').'-45T10:00', false],
        'trailing text' => [$future->format('Y-m-d\TH:i').' next week', false],
    ];
});

it('refuses to unpublish a post changed by staff after the MCP call, and hides the button (L3)', function () {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->create();
    $call = McpCall::factory()->for($ceo)->publishOf($post)->create();
    $fresh = McpCall::factory()->for($ceo)->publishOf(NewsPost::factory()->create())->create();
    DB::table('news_posts')->where('id', $post->id)->update(['updated_at' => $call->created_at->copy()->addMinutes(5)]);
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->set('tab', 'calls')
        ->assertDontSee('data-unpublish="'.$call->id.'"', false)
        ->assertSee('data-unpublish="'.$fresh->id.'"', false);

    expect(fn () => app(UnpublishMcpPost::class)->handle($call->id, $ceo))->toThrow(ValidationException::class)
        ->and($post->fresh()->status)->toBe(NewsStatus::Published);

    DB::table('news_posts')->where('id', $post->id)->update(['updated_at' => $call->created_at->copy()->addSeconds(30)]);
    app(UnpublishMcpPost::class)->handle($call->id, $ceo);
    expect($post->fresh()->status)->toBe(NewsStatus::Draft);
});

it('drops an unpublished post from the sitemap (flush on the update path)', function () {
    [$ceo] = mcpHolder();
    openSearchIndexing();
    $post = NewsPost::factory()->create();
    $call = McpCall::factory()->for($ceo)->publishOf($post)->create();

    expect($this->get('/sitemap.xml')->assertOk()->getContent())->toContain($post->slug);

    app(UnpublishMcpPost::class)->handle($call->id, $ceo);

    expect($this->get('/sitemap.xml')->assertOk()->getContent())->not->toContain($post->slug);
});

it('returns all 21 tools in one tools/list page and guards the page size', function () {
    [, $token] = mcpHolder($plain = fakeMcpTokenValue());

    $result = mcpRequest($plain)->assertOk()->json('result');
    $defaults = (new ReflectionClass(AdminServer::class))->getDefaultProperties();

    expect($result['tools'])->toHaveCount(21)->and($result)->not->toHaveKey('nextCursor')
        ->and(count(AdminServer::TOOLS))->toBeLessThanOrEqual($defaults['defaultPaginationLength'])
        ->and(count(AdminServer::TOOLS))->toBeLessThanOrEqual($defaults['maxPaginationLength']);
});

it('counts intent in the limiters: invalid and refused write calls use quota (by design)', function () {
    $admin = staffUser(Role::Admin);

    AdminServer::actingAs($admin)->tool(CreatePost::class, ['body' => ['uz' => 'no title']])->assertHasErrors();
    AdminServer::actingAs($admin)->tool(CreatePost::class, reviewPostArgs(['slug' => 'x']))->assertHasErrors();
    AdminServer::actingAs($admin)->tool(CreatePost::class, reviewPostArgs(['publish' => true, 'confirm_publish' => true, 'title' => ['uz' => 'Eng arzon']]))->assertHasErrors();

    expect(RateLimiter::attempts('mcp-write:'.$admin->id))->toBe(3)->and(RateLimiter::attempts('mcp-publish:'.$admin->id))->toBe(1)
        ->and(NewsPost::query()->count())->toBe(0);
});

it('pads text spec values to every locale like the admin form', function () {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->create(['code' => 'color']);
    DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id, 'is_required' => false, 'is_filterable' => false, 'sort' => 1]);

    $rows = app(SpecInput::class)->resolve($category->id, [['code' => 'color', 'value' => ['uz' => 'Qora']]]);

    expect($rows[0]['text'])->toBe(['uz' => 'Qora', 'ru' => '', 'en' => '']);
});

it('loads the options of all category attributes in one query', function () {
    $category = Category::factory()->create();
    foreach (range(1, 6) as $i) {
        $attribute = Attribute::factory()->select(['A', 'B'])->create(['code' => 'sel'.$i]);
        DB::table('category_attribute')->insert(['category_id' => $category->id, 'attribute_id' => $attribute->id, 'is_required' => false, 'is_filterable' => false, 'sort' => $i]);
    }
    $specs = array_map(fn (int $i): array => ['code' => 'sel'.$i, 'option' => 'a'], range(1, 6));

    $queries = countQueries(fn () => app(SpecInput::class)->resolve($category->id, $specs));

    expect($queries)->toBeLessThanOrEqual(6);
});

it('resolves FAQ owners in one place', function () {
    $page = Page::factory()->create();
    $product = Product::factory()->create();
    $category = Category::factory()->create();

    expect(FaqOwner::spec(null))->toBe('general')->and(FaqOwner::spec(''))->toBe('general')->and(FaqOwner::spec('page:3'))->toBe('page:3')
        ->and(FaqOwner::model('general'))->toBeNull()
        ->and(FaqOwner::model('page:'.$page->id)?->is($page))->toBeTrue()
        ->and(FaqOwner::model('product:'.$product->id)?->is($product))->toBeTrue()
        ->and(FaqOwner::model('category:'.$category->id)?->is($category))->toBeTrue()
        ->and(FaqOwner::model('product:99999'))->toBeNull()
        ->and(FaqOwner::model('product:1;category:2'))->toBeNull();
});

it('keeps product drafts quote-only whatever the input, so an activated draft can never be carted without a price', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();

    mcpCallTool($plain, 'create_product_draft', ['type' => 'hardware', 'category_id' => $category->id, 'sku' => 'QO-1', 'name' => ['uz' => 'Skaner']])->assertOk();

    expect(Product::query()->sole()->sale_mode->value)->toBe('quote')
        ->and(mcpCallTool($plain, 'create_product_draft', ['type' => 'hardware', 'category_id' => $category->id, 'sku' => 'QO-2', 'name' => ['uz' => 'Skaner'], 'sale_mode' => 'cart'])->json('result.isError'))->toBeTrue();
});
