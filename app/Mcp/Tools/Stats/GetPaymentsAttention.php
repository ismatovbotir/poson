<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Tools\PosonTool;
use App\Models\User;
use App\Support\Orders\OrderMetrics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * get_payments_attention (plan 13 M2g, S13–S15): payments and orders that
 * need a human. Items carry five fields only; the admin_url opens the order.
 */
#[Name('get_payments_attention')]
#[Description('Payments that need attention, in six groups: uzqr_unmatched (money received, needs a decision), uzqr_holding_expired (expired invoice the bank has not finalised), uzqr_receipt_required (fiscal receipt not confirmed), manual_unpaid (manual-method orders unpaid for manual_unpaid_days, default 3), unreconciled (paid manual or UzQR payments not ticked off against the bank statement) and refunds (refunded in the last 7 days). Each item has order_number, amount_tiyin, method, age_hours and admin_url; counts hold the full number per group. Test orders excluded.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPaymentsAttention extends PosonTool
{
    protected function permission(): ?string
    {
        return 'payments.viewAny';
    }

    public function group(): string
    {
        return 'stats';
    }

    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->permits($user);
    }

    public function permits(User $user): bool
    {
        return $user->can('payments.viewAny') && $user->can('orders.viewAny');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'manual_unpaid_days' => ['nullable', 'integer', 'between:1,90'],
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $result = (new OrderMetrics($user))->attention(
            (int) ($request->get('limit') ?: 20),
            (int) ($request->get('manual_unpaid_days') ?: 3),
        );

        $groups = $result['groups'];
        $truncated = false;

        // Keyed groups: cut the tail of the longest group until the JSON fits (M2-S2).
        while (strlen((string) json_encode(['groups' => $groups, 'counts' => $result['counts']])) > self::RESPONSE_CAP_BYTES) {
            $longest = array_reduce(
                array_keys($groups),
                fn (?string $carry, string $key) => $carry === null || count($groups[$key]) > count($groups[$carry]) ? $key : $carry,
            );

            if ($groups[$longest] === []) {
                break;
            }

            array_pop($groups[$longest]);
            $truncated = true;
        }

        return Response::structured(['groups' => $groups, 'counts' => $result['counts'], 'truncated' => $truncated]);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $item = $schema->object([
            'order_number' => $schema->string()->required(),
            'amount_tiyin' => $schema->integer()->required(),
            'method' => $schema->string()->nullable(),
            'age_hours' => $schema->integer()->required(),
            'admin_url' => $schema->string()->required(),
        ]);

        $groups = [];
        $counts = [];

        foreach (OrderMetrics::ATTENTION_GROUPS as $name) {
            $groups[$name] = $schema->array()->items($item)->required();
            $counts[$name] = $schema->integer()->required();
        }

        return [
            'groups' => $schema->object($groups)->required(),
            'counts' => $schema->object($counts)->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }
}
