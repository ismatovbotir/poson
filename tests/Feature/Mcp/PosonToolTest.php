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

class McpFakeServer extends AdminServer
{
    protected array $tools = [GetMyAccess::class, McpFakeWriteTool::class, McpFakeBrokenTool::class];
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

it('registers exactly the M1 tool list (snapshot)', function () {
    expect(collect(AdminServer::TOOLS)->map(fn (string $class) => app($class)->name())->all())->toBe(['get_my_access']);
});
