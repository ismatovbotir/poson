<?php

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Mcp\Tools\Stats\GetPaymentsAttention;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\UzqrTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

function attentionKeys(mixed $data): array
{
    $keys = [];

    foreach ((array) $data as $key => $value) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }

        if (is_array($value)) {
            $keys = [...$keys, ...attentionKeys($value)];
        }
    }

    return $keys;
}

beforeEach(function () {
    Http::preventStrayRequests();
    mcpTelegramConfigured();
});

it('returns the six groups with five-field items and no sensitive data', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Model::preventLazyLoading();

    $order = Order::factory()->totalling(250_000)->create([
        'name' => 'Bobur Leakov', 'phone' => '+998935556677', 'comment' => 'leaky comment', 'created_at' => now()->subDays(5),
        'payment_method' => PaymentMethod::BankTransfer,
    ]);
    UzqrTransaction::factory()->unmatched()->create([
        'order_id' => $order->id, 'amount' => 250_000, 'invoice_id' => 'INV-LEAK-123', 'external_id' => 'EXT-LEAK-1', 'pay_id' => 'PAYIDLEAK0000000001',
        'resolution_reference' => 'RES-LEAK-5', 'resolution_note' => 'resnote-leak',
    ]);
    OrderPayment::factory()->create([
        'order_id' => $order->id, 'amount' => 250_000, 'reference' => 'REF-LEAK-777', 'note' => 'note-leak-888', 'provider_ref' => 'PROV-LEAK-999', 'reconciled_at' => null,
    ]);

    $response = mcpCallTool($plain, 'get_payments_attention')->assertOk();
    $content = $response->json('result.structuredContent');

    expect(array_keys($content['groups']))->toBe(['uzqr_unmatched', 'uzqr_holding_expired', 'uzqr_receipt_required', 'manual_unpaid', 'unreconciled', 'refunds'])
        ->and($content['counts'])->toMatchArray(['uzqr_unmatched' => 1, 'manual_unpaid' => 1, 'unreconciled' => 1, 'refunds' => 0])
        ->and($content['truncated'])->toBeFalse();

    foreach ($content['groups'] as $items) {
        foreach ($items as $item) {
            expect(array_keys($item))->toBe(['order_number', 'amount_tiyin', 'method', 'age_hours', 'admin_url'])
                ->and($item['amount_tiyin'])->toBeInt();
        }
    }

    $raw = $response->getContent();
    foreach (['REF-LEAK-777', 'note-leak-888', 'PROV-LEAK-999', 'INV-LEAK-123', 'EXT-LEAK-1', 'PAYIDLEAK', 'RES-LEAK-5', 'resnote-leak', 'Bobur', 'Leakov', '935556677', 'leaky comment', $order->ulid] as $needle) {
        expect($raw)->not->toContain($needle);
    }

    $forbidden = ['name', 'phone', 'email', 'tin', 'inn', 'company', 'address', 'comment', 'customer_id', 'ulid', 'attribution', 'staff_note', 'reference', 'provider_ref', 'note', 'token', 'hash', 'prefix', 'ip', 'user_agent', 'ua', 'secret'];
    expect(array_intersect(attentionKeys($content), $forbidden))->toBe([]);
});

it('builds admin_url on the APP_URL host from the order id only', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    config(['app.url' => 'https://crm.example.test']);
    $order = Order::factory()->totalling(1_000)->create(['created_at' => now()->subDays(5), 'payment_method' => PaymentMethod::BankTransfer]);

    $url = mcpCallTool($plain, 'get_payments_attention')->assertOk()->json('result.structuredContent.groups.manual_unpaid.0.admin_url');

    expect(parse_url($url, PHP_URL_HOST))->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and(parse_url($url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($url, PHP_URL_PATH))->toBe(route('admin.orders.show', ['order' => $order->id], false))
        ->and(parse_url($url, PHP_URL_QUERY))->toBeNull();
});

it('excludes test orders from every group', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $test = Order::factory()->test()->totalling(1_000)->create(['created_at' => now()->subDays(9), 'payment_method' => PaymentMethod::BankTransfer]);
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $test->id]);
    OrderPayment::factory()->create(['order_id' => $test->id, 'reconciled_at' => null]);

    $content = mcpCallTool($plain, 'get_payments_attention')->assertOk()->json('result.structuredContent');

    expect(array_sum($content['counts']))->toBe(0)->and(collect($content['groups'])->flatten(1))->toBeEmpty();
});

it('validates limit and manual_unpaid_days', function (array $arguments, bool $valid) {
    mcpHolder($plain = fakeMcpTokenValue());

    $response = mcpCallTool($plain, 'get_payments_attention', $arguments)->assertOk();

    expect((bool) $response->json('result.isError'))->toBe(! $valid);
})->with([
    'limit 1' => [['limit' => 1], true],
    'limit 50' => [['limit' => 50], true],
    'limit 0' => [['limit' => 0], false],
    'limit 51' => [['limit' => 51], false],
    'limit text' => [['limit' => 'abc'], false],
    'days 1' => [['manual_unpaid_days' => 1], true],
    'days 90' => [['manual_unpaid_days' => 90], true],
    'days 0' => [['manual_unpaid_days' => 0], false],
    'days 91' => [['manual_unpaid_days' => 91], false],
]);

it('returns at most limit items per group and keeps the full counts', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    Order::factory()->count(55)->totalling(100)->create(['created_at' => now()->subDays(10), 'payment_method' => PaymentMethod::BankTransfer]);

    $content = mcpCallTool($plain, 'get_payments_attention', ['limit' => 50])->assertOk()->json('result.structuredContent');
    expect($content['groups']['manual_unpaid'])->toHaveCount(50)->and($content['counts']['manual_unpaid'])->toBe(55);

    $content = mcpCallTool($plain, 'get_payments_attention', ['limit' => 3, 'manual_unpaid_days' => 30])->assertOk()->json('result.structuredContent');
    expect($content['groups']['manual_unpaid'])->toBe([])->and($content['counts']['manual_unpaid'])->toBe(0);
});

it('is gated by orders.viewAny and payments.viewAny', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $names = collect(mcpRequest($plain)->assertOk()->json('result.tools'))->pluck('name');
    expect($names)->toContain('get_payments_attention');

    $tool = app(GetPaymentsAttention::class);
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

it('is read only, idempotent and has an output schema', function () {
    mcpHolder($plain = fakeMcpTokenValue());

    $tool = collect(mcpRequest($plain)->json('result.tools'))->firstWhere('name', 'get_payments_attention');

    expect($tool['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tool['annotations']['idempotentHint'])->toBeTrue()
        ->and($tool['outputSchema']['properties'])->toHaveKeys(['groups', 'counts']);
});

it('writes nothing to orders, payments or invoices', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $order = Order::factory()->totalling(500)->create(['created_at' => now()->subDays(9), 'payment_method' => PaymentMethod::BankTransfer]);
    $transaction = UzqrTransaction::factory()->unmatched()->create(['order_id' => $order->id]);
    $stamps = [$order->fresh()->updated_at, $transaction->fresh()->updated_at];

    mcpCallTool($plain, 'get_payments_attention')->assertOk();

    expect([$order->fresh()->updated_at, $transaction->fresh()->updated_at])->toEqual($stamps)
        ->and($transaction->fresh()->resolved_at)->toBeNull();
});
