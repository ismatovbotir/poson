<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily clean-up of carts (plan 11 v2 §1.1, security condition 14):
 *
 *  - empty carts untouched for EMPTY_DAYS (1) day;
 *  - converted carts (an order was placed, plan 11b) EMPTY_DAYS after conversion;
 *  - every other cart untouched for KEEP_DAYS (30) days (the cookie lifetime).
 *
 * Lines go with their cart (cascade). Deleted in chunks.
 */
class PruneCarts
{
    public const EMPTY_DAYS = 1;

    public const CONVERTED_DAYS = 1;

    public const KEEP_DAYS = 30;

    private const CHUNK = 500;

    /**
     * @return int Carts deleted
     */
    public function handle(): int
    {
        $now = now();

        return $this->deleteWhere(fn (Builder $query) => $query
            ->where(fn (Builder $empty) => $empty->whereDoesntHave('items')->where('updated_at', '<', $now->copy()->subDays(self::EMPTY_DAYS)))
            ->orWhere(fn (Builder $converted) => $converted->whereNotNull('converted_at')->where('converted_at', '<', $now->copy()->subDays(self::CONVERTED_DAYS)))
            ->orWhere('updated_at', '<', $now->copy()->subDays(self::KEEP_DAYS)));
    }

    /**
     * @param  callable(Builder<Cart>): mixed  $constraints
     */
    private function deleteWhere(callable $constraints): int
    {
        $deleted = 0;

        do {
            $ids = Cart::query()->where($constraints)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += Cart::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
