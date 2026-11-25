<?php

use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Enums\OrderSource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M1: schema, relations and retention (MA-15).
 */
function miniAppSilentUser(): TelegramUser
{
    return TelegramUser::factory()->registered()->create([
        'last_message_at' => now()->subMonths(13),
        'created_at' => now()->subMonths(14),
    ]);
}

function miniAppPrune(): void
{
    Artisan::call('model:prune', ['--model' => [TelegramUser::class]]);
}

it('links carts and orders to the Telegram user', function () {
    $user = tmaUser();
    $cart = Cart::factory()->forTelegramUser($user)->create();
    $order = Order::factory()->telegram($user)->create();

    expect($cart->telegramUser->is($user))->toBeTrue()
        ->and($order->telegramUser->is($user))->toBeTrue()
        ->and($order->source)->toBe(OrderSource::Telegram)
        ->and($order->phone)->toBe($user->phone)
        ->and($user->carts()->count())->toBe(1)
        ->and($user->orders()->count())->toBe(1)
        ->and(OrderSource::Telegram->label())->toBe('Telegram Mini App');
});

it('never prunes a user with a real order in the last 12 months (MA-15)', function () {
    $user = miniAppSilentUser();
    Order::factory()->telegram($user)->create(['created_at' => now()->subMonths(11)]);

    miniAppPrune();

    expect(TelegramUser::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('prunes a silent user whose only orders are TEST or older than 12 months, and keeps the orders (MA-15)', function () {
    $user = miniAppSilentUser();
    $old = Order::factory()->telegram($user)->create(['created_at' => now()->subMonths(13)]);
    $test = Order::factory()->test()->create(['telegram_user_id' => $user->id, 'created_at' => now()->subMonth()]);

    miniAppPrune();

    expect(TelegramUser::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and($old->fresh()->telegram_user_id)->toBeNull()
        ->and($test->fresh())->not->toBeNull();
});

it('never prunes a user with a cart holding items, and deletes empty carts with the user (MA-15)', function () {
    $withItems = miniAppSilentUser();
    $cart = Cart::factory()->forTelegramUser($withItems)->create();
    CartItem::factory()->for($cart)->create(['product_id' => Product::factory()->create()->id]);

    $empty = miniAppSilentUser();
    $emptyCart = Cart::factory()->forTelegramUser($empty)->create();

    miniAppPrune();

    expect(TelegramUser::query()->whereKey($withItems->id)->exists())->toBeTrue()
        ->and(TelegramUser::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($emptyCart->id)->exists())->toBeFalse();
});

it('keeps the orders of a scrubbed banned user (MA-15)', function () {
    $user = TelegramUser::factory()->registered()->banned()->create([
        'banned_at' => now()->subMonths(13),
        'last_message_at' => now()->subMonths(14),
        'created_at' => now()->subMonths(15),
    ]);
    $order = Order::factory()->telegram($user)->create(['created_at' => now()->subMonths(13)]);

    app(ScrubBannedTelegramUsers::class)->handle();

    expect($order->fresh())->not->toBeNull()
        ->and($order->fresh()->number)->toBe($order->number);
});
