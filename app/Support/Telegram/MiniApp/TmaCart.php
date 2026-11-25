<?php

namespace App\Support\Telegram\MiniApp;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Mini App cart of a Telegram user (plan 20 §5, MA-10, MA-21): a carts
 * row with telegram_user_id, found only through the signed-in user, never
 * through a token from the request. The token exists only because the cart
 * actions key on it; it is never rendered, sent as a cookie or logged.
 *
 * for() returns the open cart or creates one while the telegram_users row
 * is locked, so concurrent first adds end with one cart. Site carts are
 * separate and never merged; PruneCarts treats both alike.
 */
final class TmaCart
{
    /**
     * A converted cart this recent still answers a repeated checkout tap with its order.
     */
    public const REPLAY_MINUTES = 10;

    public static function current(TelegramUser $user): ?Cart
    {
        return Cart::query()->open()->where('telegram_user_id', $user->id)->orderByDesc('id')->first();
    }

    public static function for(TelegramUser $user, string $locale): Cart
    {
        return DB::transaction(function () use ($user, $locale): Cart {
            TelegramUser::query()->whereKey($user->id)->lockForUpdate()->first();

            $cart = self::current($user);

            if ($cart instanceof Cart) {
                return $cart;
            }

            $cart = new Cart;
            $cart->forceFill([
                'token' => strtoupper((string) Str::ulid()),
                'telegram_user_id' => $user->id,
                'locale' => in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.locale'),
            ])->save();

            return $cart;
        }, attempts: 3);
    }

    /**
     * The cart a checkout submits: the open one, else the one converted in the
     * last REPLAY_MINUTES (PlaceOrder then returns its order: a double tap
     * gets the same order).
     */
    public static function forCheckout(TelegramUser $user): ?Cart
    {
        return self::current($user) ?? Cart::query()
            ->where('telegram_user_id', $user->id)
            ->whereNotNull('converted_at')
            ->where('converted_at', '>=', now()->subMinutes(self::REPLAY_MINUTES))
            ->orderByDesc('converted_at')
            ->first();
    }

    /**
     * Lines in the user's open cart (the header counter).
     */
    public static function lineCount(TelegramUser $user): int
    {
        // One query: the lines of the newest open cart (a subquery picks it).
        return CartItem::query()
            ->where('cart_id', Cart::query()->select('id')->open()->where('telegram_user_id', $user->id)->orderByDesc('id')->limit(1))
            ->count();
    }
}
