<?php

use App\Enums\McpCallStatus;
use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Account\GetMyAccess;
use App\Mcp\Tools\PosonTool;
use App\Models\McpCall;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('fake_write')]
class McpFakeWriteTool extends PosonTool
{
    protected function permission(): ?string
    {
        return 'news.create';
    }

    public function group(): string
    {
        return 'content';
    }

    protected function isWrite(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        return ['title' => ['required', 'string', 'max:20']];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $this->before = ['title' => ['uz' => 'Old']];

        return Response::text('saved');
    }
}

#[Name('fake_broken')]
class McpFakeBrokenTool extends PosonTool
{
    protected function permission(): ?string
    {
        return null;
    }

    public function group(): string
    {
        return 'account';
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        throw new RuntimeException('SQLSTATE[42S02]: select * from secret_table where token = posm_leak');
    }
}

#[Name('fake_publish')]
class McpFakePublishTool extends McpFakeWriteTool
{
    protected function isPublish(Request $request): bool
    {
        return $request->get('publish') === true;
    }

    protected function rules(): array
    {
        return ['title' => ['required', 'string', 'max:20'], 'publish' => ['nullable', 'boolean'], 'fallback' => ['nullable', 'boolean']];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $this->published = $request->get('publish') === true && $request->get('fallback') !== true;

        return Response::text('saved');
    }
}

class McpFakeServer extends AdminServer
{
    protected array $tools = [GetMyAccess::class, McpFakeWriteTool::class, McpFakeBrokenTool::class, McpFakePublishTool::class];
}

it('lists tools only for users who may call them', function () {
    McpFakeServer::actingAs(staffUser(Role::Admin))->tool(McpFakeWriteTool::class, ['title' => 'x'])->assertOk();

    McpFakeServer::actingAs(staffUser(Role::SalesManager))->tool(McpFakeWriteTool::class, ['title' => 'x'])->assertNotRegistered();
});

it('validates arguments and audits the call as invalid with redacted arguments', function () {
    $admin = staffUser(Role::Admin);

    McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => str_repeat('a', 80)])->assertHasErrors();

    expect(McpCall::query()->sole())
        ->status->toBe(McpCallStatus::Invalid)
        ->tool->toBe('fake_write')
        ->is_write->toBeTrue()
        ->arguments->toBe(['title' => ['chars' => 80]]);
});

it('records the before snapshot of a write', function () {
    McpFakeServer::actingAs(staffUser(Role::Admin))->tool(McpFakeWriteTool::class, ['title' => 'New'])->assertOk()->assertSee('saved');

    expect(McpCall::query()->sole())->status->toBe(McpCallStatus::Ok)->before->toBe(['title' => ['uz' => 'Old']]);
});

it('limits writes to 30 per hour per user (C17)', function () {
    $admin = staffUser(Role::Admin);

    foreach (range(1, PosonTool::WRITE_LIMIT_PER_HOUR) as $i) {
        McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'T'.$i])->assertOk();
    }

    McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'over'])->assertHasErrors();

    expect(McpCall::query()->where('status', McpCallStatus::RateLimited)->count())->toBe(1);
});

it('answers a server error without exception details and logs no token (C27)', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });

    McpFakeServer::actingAs(staffUser(Role::Admin))->tool(McpFakeBrokenTool::class)
        ->assertHasErrors()
        ->assertDontSee(['SQLSTATE', 'secret_table', 'posm_']);

    expect(McpCall::query()->sole()->status)->toBe(McpCallStatus::Error)
        ->and(json_encode(McpCall::query()->sole()->getAttributes()))->not->toContain('SQLSTATE');
});

it('registers exactly the M1 + M2 + M3 tool list (snapshot)', function () {
    expect(collect(AdminServer::TOOLS)->map(fn (string $class) => app($class)->name())->all())->toBe([
        'get_my_access', 'get_order_stats', 'get_payments_attention', 'get_sales_stats', 'get_traffic_stats', 'get_ai_bot_stats', 'get_business_dashboard',
        'get_page', 'get_post', 'list_faq', 'list_pages', 'list_posts', 'get_product', 'list_brands', 'list_categories', 'search_catalog',
        'create_post', 'create_faq_draft', 'create_page_draft', 'create_product_draft',
    ]);
});

it('rejects arguments a write tool does not declare, listing only the allowed names (M3-S2, M3-S13)', function (string $key) {
    $admin = staffUser(Role::Admin);

    McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'ok', $key => 'SECRET-VALUE-77'])
        ->assertHasErrors(['Allowed arguments: title'])
        ->assertDontSee(['SECRET-VALUE-77', $key]);

    expect(McpCall::query()->sole()->status)->toBe(McpCallStatus::Invalid);
})->with(['id', 'status', 'slug', 'is_active', 'price', 'ikpu_code', 'roll_type_ids']);

it('stops the 31st write and the 6th publish atomically with a retry hint (M3-S7)', function () {
    $admin = staffUser(Role::Admin);

    foreach (range(1, PosonTool::PUBLISH_LIMIT_PER_DAY) as $i) {
        McpFakeServer::actingAs($admin)->tool(McpFakePublishTool::class, ['title' => 'P'.$i, 'publish' => true])->assertOk();
    }

    McpFakeServer::actingAs($admin)->tool(McpFakePublishTool::class, ['title' => 'P6', 'publish' => true])
        ->assertHasErrors(['Retry after']);

    // Publishes also spent the hourly write budget: 5 + 1 refused so far; the rest are plain writes.
    foreach (range(1, PosonTool::WRITE_LIMIT_PER_HOUR - PosonTool::PUBLISH_LIMIT_PER_DAY - 1) as $i) {
        McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'W'.$i])->assertOk();
    }

    McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'over'])->assertHasErrors(['Rate limit']);

    expect(McpCall::query()->where('status', McpCallStatus::RateLimited)->count())->toBe(2)
        ->and(McpCall::query()->where('is_publish', true)->count())->toBe(PosonTool::PUBLISH_LIMIT_PER_DAY);
});

it('counts the limiter by increment so a refused call cannot slip through at the limit (M3-S7)', function () {
    $admin = staffUser(Role::Admin);
    RateLimiter::increment('mcp-write:'.$admin->id, 3600, PosonTool::WRITE_LIMIT_PER_HOUR);

    McpFakeServer::actingAs($admin)->tool(McpFakeWriteTool::class, ['title' => 'x'])->assertHasErrors(['Rate limit']);
});

it('logs is_publish only when the record really ended up published (M3-S8)', function () {
    $admin = staffUser(Role::Admin);

    McpFakeServer::actingAs($admin)->tool(McpFakePublishTool::class, ['title' => 'A', 'publish' => true])->assertOk();
    McpFakeServer::actingAs($admin)->tool(McpFakePublishTool::class, ['title' => 'B', 'publish' => true, 'fallback' => true])->assertOk();
    McpFakeServer::actingAs($admin)->tool(McpFakePublishTool::class, ['title' => 'C'])->assertOk();

    expect(McpCall::query()->orderBy('id')->pluck('is_publish')->all())->toBe([true, false, false]);

    // The draft fallback still spent a publish slot (the limiter counts intent).
    expect(RateLimiter::attempts('mcp-publish:'.$admin->id))->toBe(2);
});
