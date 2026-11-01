<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Support\Period;
use App\Mcp\Tools\PosonTool;
use App\Models\User;
use App\Support\Orders\OrderMetrics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * get_order_stats (plan 13 M2g, S9–S12): order and payment totals plus one
 * grouped breakdown. Aggregates only; test orders are always excluded.
 */
#[Name('get_order_stats')]
#[Description('Order and payment totals for a period (days 1-366, default 30, or from/to dates; Asia/Tashkent days): booked, collected, refunded, net collected, order count and average order, in integer tiyin (divide by 100 for soʻm), plus a breakdown by status, payment_method, payment_status, source, delivery_type, region (oblast), day, week or month. Aggregates only, test orders excluded; no customer data.')]
#[IsReadOnly]
#[IsIdempotent]
class GetOrderStats extends PosonTool
{
    public const DEFAULT_GROUP = 'status';

    public const DEFINITIONS = [
        'booked' => 'Sum of totals of non-cancelled orders created in the period.',
        'collected' => 'Sum of ledger payments (goods and delivery) with status paid or refunded, by payment date.',
        'refunded' => 'Sum of ledger payments refunded in the period, by refund date.',
        'net_collected' => 'Collected minus refunded.',
        'orders' => 'Count of non-cancelled orders created in the period (cancelled orders are not in rows either).',
        'avg_order' => 'Booked divided by orders, rounded down.',
        'on_receipt' => 'Payment method: cash on receipt of the goods.',
        'test_orders' => 'Test orders and their payments are always excluded.',
    ];

    protected function permission(): ?string
    {
        return 'orders.viewAny';
    }

    public function group(): string
    {
        return 'stats';
    }

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->allowed($user);
    }

    public function permits(User $user): bool
    {
        return $this->allowed($user);
    }

    private function allowed(User $user): bool
    {
        return $user->can('orders.viewAny') && $user->can('payments.viewAny');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            ...Period::rules(),
            'group_by' => ['nullable', 'string', Rule::in(array_keys(OrderMetrics::GROUPS))],
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $period = Period::make($request->all());
        $groupBy = (string) ($request->get('group_by') ?: self::DEFAULT_GROUP);
        $metrics = new OrderMetrics($user);

        return $this->structuredCapped([
            'period' => ['from' => $period->fromDate(), 'to' => $period->toDate(), 'timezone' => Period::TIMEZONE],
            'totals' => $metrics->summary($period),
            'group_by' => $groupBy,
            'rows' => $metrics->rows($period, $groupBy),
            'definitions' => self::DEFINITIONS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->object([
                'from' => $schema->string()->required(),
                'to' => $schema->string()->required(),
                'timezone' => $schema->string()->required(),
            ])->required(),
            'totals' => $schema->object([
                'booked_tiyin' => $schema->integer()->required(),
                'collected_tiyin' => $schema->integer()->required(),
                'refunded_tiyin' => $schema->integer()->required(),
                'net_collected_tiyin' => $schema->integer()->required(),
                'orders' => $schema->integer()->required(),
                'avg_order_tiyin' => $schema->integer()->required(),
            ])->required(),
            'group_by' => $schema->string()->required(),
            'rows' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'label' => $schema->string()->required(),
                'orders' => $schema->integer()->required(),
                'booked_tiyin' => $schema->integer()->required(),
            ]))->required(),
            'definitions' => $schema->object()->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }
}
