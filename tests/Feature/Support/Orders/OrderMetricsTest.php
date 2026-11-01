<?php

use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Mcp\Support\Period;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use App\Support\Orders\OrderMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

function metricsOrder(int $total, string $createdUtc, array $attributes = [], bool $test = false): Order
{
    $factory = $test ? Order::factory()->test() : Order::factory();

    return $factory->totalling($total)->create(['created_at' => $createdUtc, ...$attributes]);
}

function metricsPayment(Order $order, int $amount, string $paidUtc, ?string $refundedUtc = null, array $attributes = []): OrderPayment
{
    return OrderPayment::factory()->create([
        'order_id' => $order->id,
        'amount' => $amount,
        'status' => $refundedUtc === null ? OrderPaymentStatus::Paid : OrderPaymentStatus::Refunded,
        'paid_at' => $paidUtc,
        'refunded_at' => $refundedUtc,
        ...$attributes,
    ]);
}

function metricsPeriod(string $from = '2026-10-10', string $to = '2026-10-20'): Period
{
    return Period::make(['from' => $from, 'to' => $to]);
}

it('computes the ledger totals in integer tiyin and ignores cancelled and test orders', function () {
    $admin = staffUser(Role::Admin);

    // Goods + delivery on one order, both collected.
    $a = metricsOrder(1_000_000, '2026-10-12 08:00:00');
    metricsPayment($a, 900_000, '2026-10-12 09:00:00');
    metricsPayment($a, 100_000, '2026-10-12 10:00:00', attributes: ['purpose' => OrderPaymentPurpose::Delivery, 'reference' => null, 'reference_key' => null]);

    // Refund inside the period.
    $b = metricsOrder(500_000, '2026-10-13 08:00:00');
    metricsPayment($b, 500_000, '2026-10-13 09:00:00', '2026-10-14 09:00:00');

    // Created and paid before the period, refunded inside it.
    $c = metricsOrder(200_000, '2026-09-20 08:00:00');
    metricsPayment($c, 200_000, '2026-09-20 09:00:00', '2026-10-15 09:00:00');

    // Paid inside, refunded after the period.
    $d = metricsOrder(50_000, '2026-10-16 08:00:00');
    metricsPayment($d, 50_000, '2026-10-16 09:00:00', '2026-10-25 09:00:00');

    // Cancelled order: not booked.
    metricsOrder(300_000, '2026-10-14 08:00:00', ['status' => OrderStatus::Cancelled]);

    // Test order with a paid payment and a refund: totals unchanged.
    $test = metricsOrder(700_000, '2026-10-14 08:00:00', test: true);
    metricsPayment($test, 700_000, '2026-10-14 09:00:00', '2026-10-15 09:00:00');
    metricsPayment($test, 100_000, '2026-10-14 09:00:00');

    $summary = (new OrderMetrics($admin))->summary(metricsPeriod());

    expect($summary)->toBe([
        'booked_tiyin' => 1_550_000,
        'collected_tiyin' => 1_550_000,
        'refunded_tiyin' => 700_000,
        'net_collected_tiyin' => 850_000,
        'orders' => 3,
        'avg_order_tiyin' => 516_666,
    ]);
});

it('returns zeros for an empty period', function () {
    $admin = staffUser(Role::Admin);

    expect((new OrderMetrics($admin))->summary(metricsPeriod()))->toBe([
        'booked_tiyin' => 0, 'collected_tiyin' => 0, 'refunded_tiyin' => 0,
        'net_collected_tiyin' => 0, 'orders' => 0, 'avg_order_tiyin' => 0,
    ]);
});

it('buckets days at the Tashkent midnight (23:30 and 00:30 local)', function () {
    $admin = staffUser(Role::Admin);

    metricsOrder(100, '2026-10-19 18:30:00'); // 23:30 on 10-19 in Tashkent
    metricsOrder(200, '2026-10-19 19:30:00'); // 00:30 on 10-20
    metricsOrder(400, '2026-10-20 18:59:59'); // 23:59:59 on 10-20, last second inside
    metricsOrder(800, '2026-10-20 19:00:00'); // 00:00 on 10-21, outside the period

    $rows = (new OrderMetrics($admin))->rows(metricsPeriod(), 'day');

    expect($rows)->toBe([
        ['key' => '2026-10-19', 'label' => '2026-10-19', 'orders' => 1, 'booked_tiyin' => 100],
        ['key' => '2026-10-20', 'label' => '2026-10-20', 'orders' => 2, 'booked_tiyin' => 600],
    ]);
    expect((new OrderMetrics($admin))->summary(metricsPeriod())['booked_tiyin'])->toBe(700);
});

it('buckets months and Monday weeks in Tashkent time', function () {
    $admin = staffUser(Role::Admin);

    metricsOrder(100, '2026-09-30 18:59:00'); // 23:59 on 09-30
    metricsOrder(200, '2026-09-30 19:00:00'); // 00:00 on 10-01 (Thursday)
    metricsOrder(400, '2026-10-31 19:30:00'); // 00:30 on 11-01 (Sunday)
    metricsOrder(800, '2026-10-25 19:30:00'); // 00:30 on 10-26 (Monday)

    $period = metricsPeriod('2026-09-01', '2026-11-30');
    $metrics = new OrderMetrics($admin);

    expect(array_column($metrics->rows($period, 'month'), 'booked_tiyin', 'key'))
        ->toBe(['2026-09' => 100, '2026-10' => 200 + 800, '2026-11' => 400]);

    expect(array_column($metrics->rows($period, 'week'), 'booked_tiyin', 'key'))
        ->toBe(['2026-09-28' => 100 + 200, '2026-10-26' => 800 + 400]);
});

it('groups by enum columns with labels, region as oblast and cancelled left out', function () {
    $admin = staffUser(Role::Admin);

    metricsOrder(300, '2026-10-12 08:00:00', ['payment_method' => PaymentMethod::BankTransfer]);
    metricsOrder(100, '2026-10-12 08:00:00', ['payment_method' => PaymentMethod::OnReceipt]);
    Order::factory()->totalling(500)->region()->create(['created_at' => '2026-10-12 08:00:00']);
    metricsOrder(900, '2026-10-12 08:00:00', ['status' => OrderStatus::Cancelled]);

    $metrics = new OrderMetrics($admin);
    $period = metricsPeriod();

    $methods = array_column($metrics->rows($period, 'payment_method'), 'orders', 'key');
    expect($methods)->toEqualCanonicalizing(['bank_transfer' => 1, 'uzqr_static' => 1, 'on_receipt' => 1])
        ->and(array_key_first($methods))->toBe('uzqr_static');

    $regions = array_column($metrics->rows($period, 'region'), 'booked_tiyin', 'key');
    expect($regions)->toBe(['samarkand' => 500, 'none' => 400]); // biggest first

    $status = array_column($metrics->rows($period, 'status'), 'orders', 'key');
    expect($status)->toBe(['new' => 3])
        ->and($metrics->rows($period, 'status')[0]['label'])->toBe(OrderStatus::New->label());

    foreach (['payment_status', 'source', 'delivery_type'] as $group) {
        expect(array_sum(array_column($metrics->rows($period, $group), 'orders')))->toBe(3);
    }
});

it('rejects an unknown group key', function () {
    (new OrderMetrics(staffUser(Role::Admin)))->rows(metricsPeriod(), 'id; drop');
})->throws(InvalidArgumentException::class);

it('shows nothing to a user without order access', function () {
    $engineerLess = staffUser(Role::ContentManager);
    $order = metricsOrder(1_000, '2026-10-12 08:00:00');
    metricsPayment($order, 1_000, '2026-10-12 09:00:00');

    $metrics = new OrderMetrics($engineerLess);

    expect($metrics->summary(metricsPeriod())['booked_tiyin'])->toBe(0)
        ->and($metrics->summary(metricsPeriod())['collected_tiyin'])->toBe(0)
        ->and($metrics->rows(metricsPeriod(), 'status'))->toBe([])
        ->and(array_sum($metrics->attentionCounts()))->toBe(0);
});

it('lists attention items with five fields, oldest first, and counts every group', function () {
    $admin = staffUser(Role::Admin);
    config(['app.url' => 'https://admin.example.test']);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));

    $unmatchedOrder = Order::factory()->totalling(111_00)->create();
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $unmatchedOrder->id, 'amount' => 111_00, 'paid_at' => now()->subHours(5)]);

    $holdingOrder = Order::factory()->totalling(222_00)->create();
    UzqrTransaction::factory()->create(['order_id' => $holdingOrder->id, 'amount' => 222_00, 'status' => UzqrTransactionStatus::Expired, 'expires_at' => now()->subHours(30), 'closed_at' => null]);

    $receiptOrder = Order::factory()->totalling(333_00)->create();
    UzqrTransaction::factory()->settled()->create(['order_id' => $receiptOrder->id, 'amount' => 333_00, 'settlement' => UzqrSettlement::Settled, 'paid_at' => now()->subHours(2), 'receipt_confirmed_at' => null]);

    $oldManual = Order::factory()->totalling(444_00)->create(['created_at' => now()->subDays(4), 'payment_method' => PaymentMethod::BankTransfer]);
    Order::factory()->totalling(1)->create(['created_at' => now()->subDays(1)]); // too young
    Order::factory()->totalling(2)->create(['created_at' => now()->subDays(9), 'status' => OrderStatus::Cancelled]);
    Order::factory()->totalling(3)->create(['created_at' => now()->subDays(9), 'payment_status' => PaymentStatus::Paid]);

    $paid = Order::factory()->totalling(555_00)->paid()->create();
    OrderPayment::factory()->create(['order_id' => $paid->id, 'amount' => 555_00, 'reconciled_at' => null, 'paid_at' => now()->subHours(7)]);

    $refunded = Order::factory()->totalling(666_00)->create();
    metricsPayment($refunded, 666_00, now()->subDays(3)->toDateTimeString(), now()->subHours(10)->toDateTimeString());
    $longAgo = Order::factory()->totalling(1)->create();
    metricsPayment($longAgo, 1, now()->subDays(30)->toDateTimeString(), now()->subDays(20)->toDateTimeString());

    // Test orders feed none of the groups.
    $testOrder = Order::factory()->test()->totalling(9)->create(['created_at' => now()->subDays(9)]);
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $testOrder->id]);
    OrderPayment::factory()->create(['order_id' => $testOrder->id, 'reconciled_at' => null]);

    $result = (new OrderMetrics($admin))->attention(20, 3);

    expect($result['counts'])->toBe([
        'uzqr_unmatched' => 1, 'uzqr_holding_expired' => 1, 'uzqr_receipt_required' => 1,
        'manual_unpaid' => 1, 'unreconciled' => 1, 'refunds' => 1,
    ])->and((new OrderMetrics($admin))->attentionCounts(3))->toBe($result['counts']);

    expect($result['groups']['uzqr_unmatched'])->toBe([[
        'order_number' => $unmatchedOrder->number, 'amount_tiyin' => 111_00, 'method' => 'uzqr_dynamic', 'age_hours' => 5,
        'admin_url' => 'https://admin.example.test/admin/orders/'.$unmatchedOrder->id,
    ]]);
    expect($result['groups']['uzqr_holding_expired'][0]['age_hours'])->toBe(30)
        ->and($result['groups']['manual_unpaid'][0])->toMatchArray(['order_number' => $oldManual->number, 'amount_tiyin' => 444_00, 'method' => 'bank_transfer', 'age_hours' => 96])
        ->and($result['groups']['unreconciled'][0]['age_hours'])->toBe(7)
        ->and($result['groups']['refunds'][0])->toMatchArray(['order_number' => $refunded->number, 'age_hours' => 10]);

    foreach ($result['groups'] as $items) {
        foreach ($items as $item) {
            expect(array_keys($item))->toBe(['order_number', 'amount_tiyin', 'method', 'age_hours', 'admin_url']);
        }
    }

    // The threshold moves with manual_unpaid_days.
    expect((new OrderMetrics($admin))->attentionCounts(5)['manual_unpaid'])->toBe(0);
});

it('caps items per group by the limit but counts them all', function () {
    $admin = staffUser(Role::Admin);
    Order::factory()->count(5)->totalling(100)->create(['created_at' => now()->subDays(10), 'payment_method' => PaymentMethod::BankTransfer]);

    $result = (new OrderMetrics($admin))->attention(2, 3);

    expect($result['groups']['manual_unpaid'])->toHaveCount(2)->and($result['counts']['manual_unpaid'])->toBe(5);
});

it('hides payment groups from a user without payments.viewAny', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $order = Order::factory()->totalling(100)->create(['created_at' => now()->subDays(10), 'payment_method' => PaymentMethod::BankTransfer]);
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $order->id]);

    $result = (new OrderMetrics($engineer))->attention();

    expect($result['counts']['uzqr_unmatched'])->toBe(0)->and($result['groups']['uzqr_unmatched'])->toBe([])
        ->and($result['counts']['manual_unpaid'])->toBe(1);
});

it('does not lazy load while building attention items', function () {
    Model::preventLazyLoading();
    $admin = staffUser(Role::Admin);
    $order = Order::factory()->totalling(100)->create();
    UzqrTransaction::factory()->unmatched()->create(['order_id' => $order->id]);
    UzqrTransaction::factory()->unmatched()->create(['order_id' => Order::factory()->create()->id]);

    expect((new OrderMetrics($admin))->attention()['groups']['uzqr_unmatched'])->toHaveCount(2);
});
