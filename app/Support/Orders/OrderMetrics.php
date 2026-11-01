<?php

namespace App\Support\Orders;

use App\Enums\DeliveryType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Region;
use App\Enums\UzqrTransactionStatus;
use App\Mcp\Support\Period;
use App\Mcp\Support\Presenters\Money;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\UzqrTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Order and payment totals for one user (plan 13 D7, M2-S9..S15).
 *
 * - Test orders are always excluded, with their ledger rows. No switch.
 * - Orders are scoped with Order::visibleTo($user).
 * - booked = total of non-cancelled orders created in the period.
 * - collected = ledger rows paid or refunded by paid_at; refunded = refunded
 *   rows by refunded_at; net = collected - refunded.
 * - Days are Asia/Tashkent (fixed +05:00, no DST), boundaries are UTC.
 * - Integer tiyin only. Not cached.
 */
final class OrderMetrics
{
    /**
     * Group key => column of orders (or a time bucket) and the enum that labels it.
     *
     * @var array<string, array{column: string, enum: class-string<\BackedEnum>|null}>
     */
    public const GROUPS = [
        'status' => ['column' => 'orders.status', 'enum' => OrderStatus::class],
        'payment_method' => ['column' => 'orders.payment_method', 'enum' => PaymentMethod::class],
        'payment_status' => ['column' => 'orders.payment_status', 'enum' => PaymentStatus::class],
        'source' => ['column' => 'orders.source', 'enum' => OrderSource::class],
        'delivery_type' => ['column' => 'orders.delivery_type', 'enum' => DeliveryType::class],
        'region' => ['column' => 'orders.region', 'enum' => Region::class],
        'day' => ['column' => 'orders.created_at', 'enum' => null],
        'week' => ['column' => 'orders.created_at', 'enum' => null],
        'month' => ['column' => 'orders.created_at', 'enum' => null],
    ];

    public const ATTENTION_GROUPS = [
        'uzqr_unmatched', 'uzqr_holding_expired', 'uzqr_receipt_required', 'manual_unpaid', 'unreconciled', 'refunds',
    ];

    /**
     * Refunds stay in the attention list this many days.
     */
    public const RECENT_REFUND_DAYS = 7;

    public function __construct(private readonly User $user) {}

    /**
     * @return array{booked_tiyin: int, collected_tiyin: int, refunded_tiyin: int, net_collected_tiyin: int, orders: int, avg_order_tiyin: int}
     */
    public function summary(Period $period): array
    {
        $booked = $this->bookedOrders($period)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(orders.total), 0) as aggregate_sum')
            ->toBase()->first();

        $bookedTiyin = Money::tiyin($booked->aggregate_sum ?? 0);
        $orders = (int) ($booked->aggregate_count ?? 0);

        $collected = Money::tiyin($this->ledger()
            ->whereIn('order_payments.status', [OrderPaymentStatus::Paid->value, OrderPaymentStatus::Refunded->value])
            ->where('order_payments.paid_at', '>=', $period->fromUtc())
            ->where('order_payments.paid_at', '<', $period->toUtc())
            ->sum('order_payments.amount'));

        $refunded = Money::tiyin($this->ledger()
            ->where('order_payments.status', OrderPaymentStatus::Refunded->value)
            ->where('order_payments.refunded_at', '>=', $period->fromUtc())
            ->where('order_payments.refunded_at', '<', $period->toUtc())
            ->sum('order_payments.amount'));

        return [
            'booked_tiyin' => $bookedTiyin,
            'collected_tiyin' => $collected,
            'refunded_tiyin' => $refunded,
            'net_collected_tiyin' => $collected - $refunded,
            'orders' => $orders,
            'avg_order_tiyin' => $orders > 0 ? intdiv($bookedTiyin, $orders) : 0,
        ];
    }

    /**
     * Aggregates per bucket of non-cancelled orders (same set as booked).
     *
     * @return list<array{key: string, label: string, orders: int, booked_tiyin: int}>
     */
    public function rows(Period $period, string $groupBy): array
    {
        if (! isset(self::GROUPS[$groupBy])) {
            throw new InvalidArgumentException('Unknown group.');
        }

        $group = self::GROUPS[$groupBy];
        $expression = $group['enum'] === null ? $this->bucketExpression($groupBy) : $group['column'];
        $timeBased = $group['enum'] === null;

        $query = $this->bookedOrders($period)
            ->selectRaw("{$expression} as bucket, COUNT(*) as aggregate_count, COALESCE(SUM(orders.total), 0) as aggregate_sum")
            ->groupByRaw($expression);

        $query = $timeBased ? $query->orderByRaw($expression) : $query->orderByDesc('aggregate_sum');

        return $query->toBase()->get()->map(function (object $row) use ($group): array {
            $key = $row->bucket === null ? 'none' : (string) $row->bucket;
            $enum = $group['enum'] !== null && $row->bucket !== null ? $group['enum']::tryFrom($key) : null;

            return [
                'key' => $key,
                'label' => $enum !== null && method_exists($enum, 'label') ? (string) $enum->label() : ($key === 'none' ? 'Not set' : $key),
                'orders' => (int) $row->aggregate_count,
                'booked_tiyin' => Money::tiyin($row->aggregate_sum),
            ];
        })->values()->all();
    }

    /**
     * @return array{groups: array<string, list<array{order_number: string, amount_tiyin: int, method: string|null, age_hours: int, admin_url: string}>>, counts: array<string, int>}
     */
    public function attention(int $limit = 20, int $manualUnpaidDays = 3): array
    {
        $limit = max(1, min(50, $limit));

        $groups = [];

        foreach (self::ATTENTION_GROUPS as $name) {
            $groups[$name] = $this->attentionQuery($name, $manualUnpaidDays) === null ? [] : $this->items($name, $limit, $manualUnpaidDays);
        }

        return ['groups' => $groups, 'counts' => $this->attentionCounts($manualUnpaidDays)];
    }

    /**
     * @return array<string, int>
     */
    public function attentionCounts(int $manualUnpaidDays = 3): array
    {
        $counts = [];

        foreach (self::ATTENTION_GROUPS as $name) {
            $query = $this->attentionQuery($name, $manualUnpaidDays);
            $counts[$name] = $query === null ? 0 : $query->count();
        }

        return $counts;
    }

    /**
     * @return list<array{order_number: string, amount_tiyin: int, method: string|null, age_hours: int, admin_url: string}>
     */
    private function items(string $name, int $limit, int $manualUnpaidDays): array
    {
        $query = $this->attentionQuery($name, $manualUnpaidDays);

        if ($query === null) {
            return [];
        }

        if ($name === 'manual_unpaid') {
            return $query->orderBy('orders.created_at')->orderBy('orders.id')->limit($limit)
                ->get(['orders.id', 'orders.number', 'orders.total', 'orders.payment_method', 'orders.created_at'])
                ->map(fn (Order $order): array => $this->item($order->id, $order->number, $order->total, $order->payment_method?->value, $order->created_at))
                ->all();
        }

        if (in_array($name, ['unreconciled', 'refunds'], true)) {
            $dateColumn = $name === 'refunds' ? 'refunded_at' : 'paid_at';

            return $query->with('order:id,number')->orderBy($dateColumn)->orderBy('id')->limit($limit)
                ->get(['id', 'order_id', 'amount', 'method', 'paid_at', 'refunded_at', 'created_at'])
                ->map(fn (OrderPayment $payment): array => $this->item(
                    $payment->order_id, (string) $payment->order?->number, $payment->amount, $payment->method?->value,
                    $payment->{$dateColumn} ?? $payment->created_at,
                ))->all();
        }

        $dateColumn = match ($name) {
            'uzqr_holding_expired' => 'expires_at',
            default => 'paid_at',
        };

        return $query->with('order:id,number')->orderBy($dateColumn)->orderBy('id')->limit($limit)
            ->get(['id', 'order_id', 'amount', 'paid_at', 'expires_at', 'created_at'])
            ->map(fn (UzqrTransaction $transaction): array => $this->item(
                $transaction->order_id, (string) $transaction->order?->number, $transaction->amount,
                PaymentMethod::UzqrDynamic->value, $transaction->{$dateColumn} ?? $transaction->created_at,
            ))->all();
    }

    /**
     * @return Builder<Order>|Builder<OrderPayment>|Builder<UzqrTransaction>|null null = the user may not see this group
     */
    private function attentionQuery(string $name, int $manualUnpaidDays): ?Builder
    {
        $manualUnpaidDays = max(1, min(90, $manualUnpaidDays));
        $orderScope = fn (Builder $order) => $order->real()->visibleTo($this->user);
        $canPayments = $this->user->can('payments.viewAny');

        if ($name === 'manual_unpaid') {
            return Order::query()->real()->visibleTo($this->user)
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->where('orders.payment_status', PaymentStatus::Unpaid->value)
                ->whereIn('orders.payment_method', array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::manual()))
                ->where('orders.created_at', '<=', now()->subDays($manualUnpaidDays));
        }

        if (! $canPayments) {
            return null;
        }

        return match ($name) {
            'unreconciled' => OrderPayment::query()->unreconciled()->where('order_payments.is_test', false)->whereHas('order', $orderScope),
            'refunds' => OrderPayment::query()->where('order_payments.status', OrderPaymentStatus::Refunded->value)
                ->where('order_payments.is_test', false)
                ->where('order_payments.refunded_at', '>=', now()->subDays(self::RECENT_REFUND_DAYS))
                ->whereHas('order', $orderScope),
            'uzqr_unmatched' => UzqrTransaction::query()->unresolvedUnmatched()->whereHas('order', $orderScope),
            'uzqr_receipt_required' => UzqrTransaction::query()->receiptRequired()->whereHas('order', $orderScope),
            'uzqr_holding_expired' => UzqrTransaction::query()
                ->where('uzqr_transactions.status', UzqrTransactionStatus::Expired->value)
                ->whereNull('uzqr_transactions.closed_at')
                ->whereHas('order', $orderScope),
            default => null,
        };
    }

    /**
     * @return array{order_number: string, amount_tiyin: int, method: string|null, age_hours: int, admin_url: string}
     */
    private function item(int $orderId, string $number, int $amount, ?string $method, ?CarbonInterface $since): array
    {
        return [
            'order_number' => $number,
            'amount_tiyin' => Money::tiyin($amount),
            'method' => $method,
            'age_hours' => $since === null ? 0 : max(0, intdiv(now()->getTimestamp() - $since->getTimestamp(), 3600)),
            'admin_url' => rtrim((string) config('app.url'), '/').route('admin.orders.show', ['order' => $orderId], false),
        ];
    }

    /**
     * Non-cancelled real orders created in the period, visible to the user.
     *
     * @return Builder<Order>
     */
    private function bookedOrders(Period $period): Builder
    {
        return Order::query()->real()->visibleTo($this->user)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->where('orders.created_at', '>=', $period->fromUtc())
            ->where('orders.created_at', '<', $period->toUtc());
    }

    /**
     * Ledger rows of real, visible orders (test rows and rows of test orders are out).
     *
     * @return Builder<OrderPayment>
     */
    private function ledger(): Builder
    {
        return OrderPayment::query()
            ->where('order_payments.is_test', false)
            ->whereIn('order_payments.order_id', Order::query()->real()->visibleTo($this->user)->select('orders.id'));
    }

    /**
     * Tashkent day / Monday-week / month of orders.created_at (UTC column).
     */
    private function bucketExpression(string $groupBy): string
    {
        return Period::sqlBucket('orders.created_at', $groupBy, DB::connection()->getDriverName());
    }
}
