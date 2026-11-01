<?php

use App\Enums\OrderPaymentStatus;
use App\Enums\Role;
use App\Mcp\Tools\Stats\GetOrderStats;
use App\Models\McpCall;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

const MCP_FORBIDDEN_KEYS = [
    'name', 'phone', 'email', 'tin', 'inn', 'company', 'address', 'comment', 'customer_id', 'ulid', 'attribution',
    'staff_note', 'price_dealer', 'price_wholesale', 'price_level', 'reference', 'provider_ref', 'note', 'token',
    'hash', 'prefix', 'ip', 'user_agent', 'ua', 'secret',
];

/**
 * @return list<string>
 */
function mcpKeysOf(mixed $data): array
{
    $keys = [];

    foreach ((array) $data as $key => $value) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }

        if (is_array($value)) {
            $keys = [...$keys, ...mcpKeysOf($value)];
        }
    }

    return $keys;
}

beforeEach(function () {
    Http::preventStrayRequests();
    mcpTelegramConfigured();
});

it('returns totals, rows and definitions from the ledger', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $today = now()->toDateTimeString();

    $order = Order::factory()->totalling(1_000_000)->create(['created_at' => $today]);
    OrderPayment::factory()->create(['order_id' => $order->id, 'amount' => 1_000_000, 'paid_at' => $today]);
    $refunded = Order::factory()->totalling(400_000)->create(['created_at' => $today]);
    OrderPayment::factory()->create(['order_id' => $refunded->id, 'amount' => 400_000, 'status' => OrderPaymentStatus::Refunded, 'paid_at' => $today, 'refunded_at' => $today]);
    $test = Order::factory()->test()->totalling(9_000_000)->create(['created_at' => $today]);
    OrderPayment::factory()->create(['order_id' => $test->id, 'amount' => 9_000_000, 'paid_at' => $today]);

    $content = mcpCallTool($plain, 'get_order_stats', ['days' => 7, 'group_by' => 'payment_status'])->assertOk()->json('result.structuredContent');

    expect($content['totals'])->toBe([
        'booked_tiyin' => 1_400_000, 'collected_tiyin' => 1_400_000, 'refunded_tiyin' => 400_000,
        'net_collected_tiyin' => 1_000_000, 'orders' => 2, 'avg_order_tiyin' => 700_000,
    ])->and($content['group_by'])->toBe('payment_status')
        ->and($content['rows'][0])->toHaveKeys(['key', 'label', 'orders', 'booked_tiyin'])
        ->and($content['definitions']['on_receipt'])->toContain('cash on receipt')
        ->and($content['truncated'])->toBeFalse()
        ->and($content['period']['timezone'])->toBe('Asia/Tashkent');

    expect(McpCall::query()->sole())->tool->toBe('get_order_stats');
});

it('defaults to status grouping and 30 days', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Order::factory()->totalling(500)->create();

    $content = mcpCallTool($plain, 'get_order_stats')->assertOk()->json('result.structuredContent');

    expect($content['group_by'])->toBe('status')->and($content['rows'][0]['key'])->toBe('new');
});

it('accepts every allowed group key', function (string $group) {
    mcpHolder($plain = fakeMcpTokenValue());
    Order::factory()->totalling(500)->create();

    $content = mcpCallTool($plain, 'get_order_stats', ['group_by' => $group])->assertOk()->json('result.structuredContent');

    expect($content['rows'])->toHaveCount(1)->and($content['rows'][0]['orders'])->toBe(1);
})->with(['status', 'payment_method', 'payment_status', 'source', 'delivery_type', 'region', 'day', 'week', 'month']);

it('rejects bad arguments without echoing them', function (array $arguments) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'get_order_stats', $arguments)->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->getContent())->not->toContain('drop')->not->toContain('structuredContent');
})->with([
    'sql in group' => [['group_by' => 'status;drop']],
    'column name' => [['group_by' => 'id']],
    'customer column' => [['group_by' => 'phone']],
    'days zero' => [['days' => 0]],
    'days too many' => [['days' => 367]],
    'array group' => [['group_by' => ['status']]],
]);

it('never leaks customer data, references or forbidden keys', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $order = Order::factory()->totalling(1_000)->company('301234567', 'Secret Savdo MChJ')->region()->create([
        'name' => 'Zafar Karimovich Leakov', 'phone' => '+998901112233', 'comment' => 'ring the bell twice',
        'staff_note' => 'vip customer note', 'address' => 'Registon 1 secret street',
    ]);
    OrderPayment::factory()->create([
        'order_id' => $order->id, 'amount' => 1_000, 'reference' => 'REF-LEAK-777', 'note' => 'note-leak-888', 'provider_ref' => 'PROV-LEAK-999',
    ]);

    foreach (['status', 'region', 'day', 'payment_method'] as $group) {
        $response = mcpCallTool($plain, 'get_order_stats', ['group_by' => $group])->assertOk();
        $raw = $response->getContent();

        foreach (['REF-LEAK-777', 'note-leak-888', 'PROV-LEAK-999', 'Zafar', 'Leakov', '998901112233', '901112233', 'Secret Savdo', '301234567', 'vip customer', 'Registon', 'ring the bell', $order->ulid, $order->number] as $needle) {
            expect($raw)->not->toContain($needle);
        }

        $keys = mcpKeysOf($response->json('result.structuredContent'));
        expect(array_intersect($keys, MCP_FORBIDDEN_KEYS))->toBe([]);
    }
});

it('limits day rows to the period and works under preventLazyLoading', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Model::preventLazyLoading();

    foreach (range(0, 59) as $day) {
        Order::factory()->totalling(100)->create(['created_at' => now()->subDays($day)]);
    }

    $content = mcpCallTool($plain, 'get_order_stats', ['days' => 366, 'group_by' => 'day'])->assertOk()->json('result.structuredContent');

    expect(count($content['rows']))->toBeLessThanOrEqual(366)->and($content['totals']['orders'])->toBe(60);
});

it('is listed and callable for the admin holder and gated by role', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $names = collect(mcpRequest($plain)->assertOk()->json('result.tools'))->pluck('name');
    expect($names)->toContain('get_order_stats');

    $tool = app(GetOrderStats::class);
    $matrix = [
        Role::Admin->value => true,
        Role::SalesManager->value => true,
        Role::ServiceEngineer->value => false,
        Role::ContentManager->value => false,
    ];

    foreach ($matrix as $role => $allowed) {
        expect($tool->permits(User::factory()->withRole(Role::from($role))->create()))->toBe($allowed, $role);
    }

    expect($tool->group())->toBe('stats');
});

it('marks the tool read only and idempotent with an output schema', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $tool = collect(mcpRequest($plain)->json('result.tools'))->firstWhere('name', 'get_order_stats');

    expect($tool['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tool['annotations']['idempotentHint'])->toBeTrue()
        ->and($tool['outputSchema']['properties'])->toHaveKeys(['totals', 'rows', 'definitions']);
});

it('writes nothing to orders or payments', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $order = Order::factory()->totalling(500)->create();
    $payment = OrderPayment::factory()->create(['order_id' => $order->id]);
    $before = [$order->fresh()->updated_at, $payment->fresh()->updated_at, Order::count(), OrderPayment::count()];

    mcpCallTool($plain, 'get_order_stats')->assertOk();

    expect([$order->fresh()->updated_at, $payment->fresh()->updated_at, Order::count(), OrderPayment::count()])->toEqual($before);
});
