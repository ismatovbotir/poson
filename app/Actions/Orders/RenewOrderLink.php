<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renew the customer's /o link (plan 11 v2 §1.10, security condition 28):
 * orders.update, real orders only, at most checkout.link_renewals_per_day
 * per order and Tashkent day. A new random ULID (the old link is a 404 from
 * now on) valid for checkout.public_link_days days; logged.
 */
class RenewOrderLink
{
    use LocksOrder;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, User $actor): string
    {
        return DB::transaction(function () use ($order, $actor): string {
            $order = $this->lockOrder($order);

            if ($order->is_test) {
                throw ValidationException::withMessages(['link' => __('orders.errors.test_no_link')]);
            }

            $today = $order->activities()
                ->where('type', ActivityType::Order->value)
                ->where('meta->action', 'link_renewed')
                ->where('created_at', '>=', now(Lead::TIMEZONE)->startOfDay()->utc())
                ->count();

            if ($today >= (int) config('checkout.link_renewals_per_day', 10)) {
                throw ValidationException::withMessages(['link' => __('orders.errors.link_renew_limit')]);
            }

            $ulid = strtoupper((string) Str::ulid());

            $order->forceFill([
                'ulid' => $ulid,
                'public_until' => now()->addDays((int) config('checkout.public_link_days', 90)),
            ])->save();

            $this->log->handle($order, 'link_renewed', $actor);

            return $ulid;
        });
    }
}
