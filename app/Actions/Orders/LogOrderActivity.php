<?php

namespace App\Actions\Orders;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Order;
use App\Models\User;

/**
 * One entry in an order's history (activities, morph alias "order"):
 * type order (status, edits, links, invoices) or payment (ledger changes).
 * meta: {action, …} with structured values only (amounts in tiyin, enum
 * values, masked references); never a tax id, address or full reference.
 */
class LogOrderActivity
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(Order $order, string $action, ?User $actor, array $meta = [], ActivityType $type = ActivityType::Order, ?string $body = null): Activity
    {
        return $order->activities()->create([
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'meta' => ['action' => $action, ...array_filter($meta, fn (mixed $value): bool => $value !== null)],
            'created_at' => now(),
        ]);
    }
}
