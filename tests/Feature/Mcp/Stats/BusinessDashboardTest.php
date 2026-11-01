<?php

use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Support\Period;
use App\Mcp\Tools\Stats\GetBusinessDashboard;
use App\Models\ApiToken;
use App\Models\Lead;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Support\Dashboard\BusinessMetrics;
use App\Support\Orders\OrderMetrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: string}
 */
function dashboardHolder(Role $role): array
{
    $user = staffUser($role);
    $user->forceFill(['mcp_access_granted_at' => now()->subDay()])->saveQuietly();
    ApiToken::factory()->mcp($user)->withPlainText($plain = fakeMcpTokenValue())->create();

    return [$user->fresh(), $plain];
}

/**
 * @return array<string, mixed>
 */
function dashboard(string $token, array $args = []): array
{
    return mcpCallTool($token, 'get_business_dashboard', $args)->assertOk()->json('result.structuredContent');
}

function dashboardKeys(mixed $data): array
{
    $keys = [];

    foreach ((array) $data as $key => $value) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }

        if (is_array($value)) {
            $keys = [...$keys, ...dashboardKeys($value)];
        }
    }

    return $keys;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('gives an admin every section equal to the shared metrics', function () {
    [$admin, $token] = dashboardHolder(Role::Admin);
    Lead::factory()->count(2)->create();
    Quote::factory()->create();
    Order::factory()->totalling(500_000)->create();
    NewsPost::factory()->published()->create();
    NewsPost::factory()->draft()->create();

    $content = dashboard($token);
    $business = new BusinessMetrics($admin);

    expect($content['omitted_sections'])->toBe([])
        ->and($content)->not->toHaveKey('previous')
        ->and($content['leads'])->toBe($business->leadCounts())
        ->and($content['quotes'])->toBe($business->quoteSummary())
        ->and($content['quotes']['by_status'])->toHaveKeys(array_column(QuoteStatus::cases(), 'value'))
        ->and($content['orders'])->toBe((new OrderMetrics($admin))->summary(Period::make(['days' => 30])))
        ->and($content['orders']['booked_tiyin'])->toBe(500_000)
        ->and($content['payments_attention']['counts'])->toBe((new OrderMetrics($admin))->attentionCounts())
        ->and($content['catalog'])->toBe($business->catalogCounts())
        ->and($content['content'])->toBe(['posts_published' => 1, 'posts_drafts' => 1])
        ->and($content['traffic'])->toHaveKeys(['visitors', 'page_views', 'leads', 'conversion_percent'])
        ->and($content['ai_bots'])->toHaveKeys(['hits', 'verified_hits'])
        ->and($content['period']['days'])->toBe(30);
});

it('omits the sections a content manager may not see without numbers', function () {
    $user = staffUser(Role::ContentManager);
    Lead::factory()->create();
    Quote::factory()->create();
    Order::factory()->totalling(900_000)->create();
    NewsPost::factory()->published()->create();

    AdminServer::actingAs($user)->tool(GetBusinessDashboard::class)->assertOk()->assertStructuredContent([
        'period' => ['from' => Period::make([])->fromDate(), 'to' => Period::make([])->toDate(), 'days' => 30],
        'content' => ['posts_published' => 1, 'posts_drafts' => 0],
        'catalog' => (new BusinessMetrics($user))->catalogCounts(),
        'omitted_sections' => ['leads', 'quotes', 'orders', 'payments_attention', 'traffic', 'ai_bots'],
        'truncated' => false,
    ]);
});

it('shows a sales manager only the own lead counts', function () {
    $sales = staffUser(Role::SalesManager);
    $other = User::factory()->create();
    Lead::factory()->create(['manager_id' => $sales->id]);
    Lead::factory()->count(2)->create(['manager_id' => $other->id]);
    $business = new BusinessMetrics($sales);
    $orders = new OrderMetrics($sales);
    $period = Period::make([]);

    expect($business->leadCounts()['leads_new'])->toBe(1);

    AdminServer::actingAs($sales)->tool(GetBusinessDashboard::class)->assertOk()->assertStructuredContent([
        'period' => ['from' => $period->fromDate(), 'to' => $period->toDate(), 'days' => 30],
        'leads' => $business->leadCounts(),
        'quotes' => $business->quoteSummary(),
        'orders' => $orders->summary($period),
        'payments_attention' => ['counts' => $orders->attentionCounts()],
        'catalog' => $business->catalogCounts(),
        'omitted_sections' => ['traffic', 'ai_bots', 'content'],
        'truncated' => false,
    ]);
});

it('adds a previous block only when compare is set', function () {
    [, $token] = dashboardHolder(Role::Admin);
    Order::factory()->totalling(100_000)->create(['created_at' => now()->subDays(40)]);

    $content = dashboard($token, ['days' => 30, 'compare' => true]);

    expect($content['previous'])->toHaveKeys(['period', 'orders', 'traffic', 'ai_bots'])
        ->and($content['previous']['orders']['booked_tiyin'])->toBe(100_000)
        ->and($content['orders']['booked_tiyin'])->toBe(0)
        ->and($content['previous']['period']['days'])->toBe(30)
        ->and(dashboard($token, ['compare' => false]))->not->toHaveKey('previous');
});

it('stays within the query budget for 366 days under preventLazyLoading', function (bool $compare, int $budget) {
    $admin = staffUser(Role::Admin);
    Order::factory()->count(3)->totalling(1_000)->create();
    Quote::factory()->create();
    Model::preventLazyLoading();
    $admin->can('leads.viewAny');

    DB::flushQueryLog();
    DB::enableQueryLog();
    AdminServer::actingAs($admin)->tool(GetBusinessDashboard::class, ['days' => 366, 'compare' => $compare])->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual($budget);
})->with([
    'plain, 25 queries' => [false, 25],
    'compare, 30 queries' => [true, 30],
]);

it('rejects bad arguments', function (array $args) {
    [, $token] = dashboardHolder(Role::Admin);

    expect(mcpCallTool($token, 'get_business_dashboard', $args)->assertOk()->json('result.isError'))->toBeTrue();
})->with([
    'days' => [['days' => 367]],
    'from' => [['from' => '2026-01-01', 'to' => '2026-01-10']],
    'compare' => [['compare' => 'maybe']],
]);

it('leaks no customer or credential keys', function () {
    [, $token] = dashboardHolder(Role::Admin);
    $lead = Lead::factory()->create(['email' => 'secret@example.test']);
    Order::factory()->totalling(1_000)->create();

    $response = mcpCallTool($token, 'get_business_dashboard', ['compare' => true])->assertOk();
    $forbidden = ['name', 'phone', 'email', 'tin', 'inn', 'company', 'address', 'comment', 'customer_id', 'ulid', 'attribution', 'staff_note', 'price_dealer', 'price_wholesale', 'price_level', 'reference', 'provider_ref', 'note', 'token', 'hash', 'prefix', 'ip', 'user_agent', 'ua', 'secret'];

    expect(array_intersect(dashboardKeys($response->json('result.structuredContent')), $forbidden))->toBe([])
        ->and($response->getContent())->not->toContain($lead->phone)->not->toContain('secret@example.test');
});

it('writes only the mcp_calls row', function () {
    [, $token] = dashboardHolder(Role::Admin);
    $before = [
        'leads' => Lead::count(), 'orders' => Order::count(), 'quotes' => Quote::count(), 'posts' => NewsPost::count(),
    ];

    mcpCallTool($token, 'get_business_dashboard', ['compare' => true])->assertOk();

    expect(McpCall::query()->sole()->tool)->toBe('get_business_dashboard')
        ->and([Lead::count(), Order::count(), Quote::count(), NewsPost::count()])->toBe(array_values($before));
    Http::assertNothingSent();
});
