<?php

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Telegram\QueueOrderStatusMessage;
use App\Actions\Telegram\RegisterTelegramUser;
use App\Actions\Telegram\SetConversationBan;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Events\Orders\OrderPlaced;
use App\Events\Orders\OrderStatusChanged;
use App\Jobs\DeliverBotMessage;
use App\Jobs\DeliverOrderStatusMessage;
use App\Jobs\SyncMiniAppMenuButton;
use App\Listeners\NotifyTelegramCustomer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WebAppButton;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\TmaTarget;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/*
| Plan 20 M5: the bot side of the Mini App. Menu button, "Open catalog",
| bot username, customer order status messages. Fake token, Http::fake only.
*/
beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77, 'username' => 'poson_test_bot']])]);
    tmaEnabled();
    BotUsername::remember('poson_test_bot');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/**
 * @return list<array{method: string, data: array<string, mixed>}>
 */
function m5Calls(string $method): array
{
    return array_values(array_filter(telegramApiCalls(), fn (array $call): bool => $call['method'] === $method));
}

it('sets a per-chat web_app menu button on the canonical https Mini App URL', function () {
    $user = tmaUser(['telegram_id' => 5550101, 'locale' => 'ru']);

    (new SyncMiniAppMenuButton($user->id))->handle(app(TelegramClient::class));

    $call = m5Calls('setChatMenuButton')[0];

    expect($call['data'])->toBe([
        'menu_button' => ['type' => 'web_app', 'text' => __('telegram_bot.menu.open_app', [], 'ru'), 'web_app' => ['url' => 'https://poson.example.uz/tma']],
        'chat_id' => '5550101',
    ]);
});

it('sets the default button for users outside the audience and on reset', function (Closure $setup, bool $reset) {
    $user = tmaUser(['telegram_id' => 5550102]);
    $setup($user);

    (new SyncMiniAppMenuButton($user->id, $reset))->handle(app(TelegramClient::class));

    expect(m5Calls('setChatMenuButton')[0]['data'])->toBe(['menu_button' => ['type' => 'default'], 'chat_id' => '5550102']);
})->with([
    'banned' => [fn (TelegramUser $user) => $user->forceFill(['banned_at' => now()])->save(), false],
    'Mini App off' => [fn () => tmaEnabled(miniApp: false), false],
    'bot off' => [fn () => tmaEnabled(bot: false), false],
    'not registered' => [fn (TelegramUser $user) => $user->forceFill(['state' => TelegramUserState::Inn])->save(), false],
    'reset' => [fn () => null, true],
]);

it('queues menu syncs after commit on the default queue with ids only', function () {
    $job = new SyncMiniAppMenuButton(5, reset: true);

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->queue)->toBe('default')
        ->and(serialize($job))->not->toContain('https')->not->toContain(TG_BOT_TOKEN);
});

it('syncs the menu button when a customer registers, changes language or is banned', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = tmaUser(['telegram_id' => 5550001]);

    app(SetConversationBan::class)->ban($user, $this->admin, null);
    app(SetConversationBan::class)->unban($user->fresh(), $this->admin, null);
    postTelegramUpdate(['update_id' => 9101, 'callback_query' => ['id' => 'cb1', 'data' => 'lang:en', 'from' => ['id' => 5550001, 'is_bot' => false, 'first_name' => 'Ali'], 'message' => ['message_id' => 5, 'chat' => ['id' => 5550001, 'type' => 'private']]]])->assertOk();

    Queue::assertPushed(SyncMiniAppMenuButton::class, 3);
    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && ! $job->reset);
});

it('does not queue the registration menu sync while the Mini App switch is off', function () {
    tmaEnabled(miniApp: false);
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = TelegramUser::factory()->create(['state' => TelegramUserState::Inn, 'phone' => '+998901234567', 'name' => 'Ali', 'consent_at' => now()]);

    app(RegisterTelegramUser::class)->handle($user);

    Queue::assertNotPushed(SyncMiniAppMenuButton::class);
});

it('answers "Catalog" with an inline web_app "Open catalog" button for the audience', function () {
    tmaUser(['telegram_id' => 5550001, 'locale' => 'en']);

    postTelegramUpdate(telegramPrivateUpdate(9201, ['text' => __('telegram_bot.menu.catalog', [], 'en')]))->assertOk();

    $call = m5Calls('sendMessage')[0]['data'];

    expect($call['text'])->toBe(__('telegram_bot.menu.open_app_reply', [], 'en'))
        ->and($call)->not->toHaveKey('parse_mode')
        ->and($call['reply_markup'])->toBe(['inline_keyboard' => [[['text' => 'Open catalog', 'web_app' => ['url' => 'https://poson.example.uz/tma']]]]]);
});

it('keeps the site catalog link for users outside the audience', function () {
    tmaEnabled(miniApp: false);
    tmaUser(['telegram_id' => 5550001, 'locale' => 'en']);

    postTelegramUpdate(telegramPrivateUpdate(9202, ['text' => __('telegram_bot.menu.catalog', [], 'en')]))->assertOk();

    $call = m5Calls('sendMessage')[0]['data'];

    expect($call['text'])->toBe(__('telegram_bot.menu.catalog_reply', ['url' => route('site.catalog.index', ['locale' => 'en'])], 'en'))
        ->and(json_encode($call['reply_markup']))->not->toContain('web_app');
});

it('stores the bot username only when it is valid and builds startapp links from TmaTarget values only', function () {
    expect(BotUsername::remember('"/../'))->toBeFalse()
        ->and(BotUsername::get())->toBe('poson_test_bot')
        ->and(BotUsername::startAppLink('o42'))->toBe('https://t.me/poson_test_bot?startapp=o42')
        ->and(BotUsername::startAppLink('orders'))->toBe('https://t.me/poson_test_bot?startapp=orders')
        ->and(BotUsername::startAppLink('o42&x=1'))->toBeNull()
        ->and(BotUsername::startAppLink('tma'))->toBeNull();
});

it('refreshes the username and queues one sync per registered, unblocked chat with --all', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    Setting::query()->where('key', BotUsername::KEY)->delete();
    tmaUser();
    tmaUser();
    tmaUser(['blocked_at' => now()]);
    TelegramUser::factory()->create();

    $this->artisan('telegram:mini-app-menu', ['--all' => true])->assertSuccessful();

    Queue::assertPushed(SyncMiniAppMenuButton::class, 2);
    Queue::assertPushedOn('default', SyncMiniAppMenuButton::class);
    expect(BotUsername::get())->toBe('poson_test_bot')
        ->and(m5Calls('setChatMenuButton'))->toBe([]);
});

it('queues one chat with --chat and refuses unknown chats', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = tmaUser(['telegram_id' => 5550222]);

    $this->artisan('telegram:mini-app-menu', ['--chat' => '5550222', '--reset' => true])->assertSuccessful();
    $this->artisan('telegram:mini-app-menu', ['--chat' => '999'])->assertFailed();
    $this->artisan('telegram:mini-app-menu')->assertExitCode(2);

    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && $job->reset);
    Queue::assertPushed(SyncMiniAppMenuButton::class, 1);
});

it('sends one stored status message with one URL button when staff confirm a Mini App order', function () {
    $user = tmaUser(['telegram_id' => 5550301, 'locale' => 'ru']);
    $order = Order::factory()->telegram($user)->create(['number' => 'O-2026-0301']);

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    $row = TelegramMessage::query()->where('order_id', $order->id)->sole();
    $call = m5Calls('sendMessage')[0]['data'];

    expect($row->telegram_user_id)->toBe($user->id)
        ->and($row->direction)->toBe(TelegramDirection::Out)
        ->and($row->kind)->toBe(TelegramMessageKind::Text)
        ->and($row->user_id)->toBeNull()
        ->and($row->order_status)->toBe(OrderStatus::Confirmed)
        ->and($row->status)->toBe(TelegramMessageStatus::Sent)
        ->and($row->telegram_message_id)->toBe(77)
        ->and($row->text)->toBe('Статус заказа O-2026-0301: Подтверждён.')
        ->and($call['chat_id'])->toBe('5550301')
        ->and($call['text'])->toBe($row->text)
        ->and($call)->not->toHaveKey('parse_mode')
        ->and($call['reply_markup'])->toBe(['inline_keyboard' => [[['text' => 'Открыть заказ', 'url' => 'https://t.me/poson_test_bot?startapp=o'.$order->id]]]]);
});

it('announces placed, confirmed, shipped, completed and cancelled but not processing', function () {
    Queue::fake([DeliverOrderStatusMessage::class]);
    $user = tmaUser();
    $order = Order::factory()->telegram($user)->create();
    $queue = app(QueueOrderStatusMessage::class);

    foreach (OrderStatus::cases() as $status) {
        $queue->handle($order->id, $status);
    }

    expect(TelegramMessage::query()->where('order_id', $order->id)->pluck('order_status')->map->value->sort()->values()->all())
        ->toBe(['cancelled', 'completed', 'confirmed', 'new', 'shipped']);
    Queue::assertPushed(DeliverOrderStatusMessage::class, 5);
});

it('sends a "placed" message from OrderPlaced for Mini App orders only', function () {
    $user = tmaUser();
    $mini = Order::factory()->telegram($user)->create();
    $site = Order::factory()->create();

    OrderPlaced::dispatch($mini);
    OrderPlaced::dispatch($site);

    expect(TelegramMessage::query()->whereNotNull('order_id')->pluck('order_id')->all())->toBe([$mini->id])
        ->and(TelegramMessage::query()->where('order_id', $mini->id)->value('order_status'))->toBe(OrderStatus::New);
});

it('dispatches OrderStatusChanged after commit from ChangeOrderStatus and a cancelling refund', function () {
    Event::fake([OrderStatusChanged::class]);
    $order = Order::factory()->create();
    $paid = Order::factory()->bankTransfer()->paid()->totalling(100_00)->status(OrderStatus::Confirmed)->create();
    $payment = OrderPayment::factory()->for($paid)->create(['amount' => 100_00]);

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);
    app(RecordOrderRefund::class)->handle($paid, $payment, 'Customer asked', $this->admin);

    Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->order->is($order) && $event->from === OrderStatus::New && $event->to === OrderStatus::Confirmed);
    Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->order->is($paid) && $event->from === OrderStatus::Confirmed && $event->to === OrderStatus::Cancelled);
    Event::assertDispatchedTimes(OrderStatusChanged::class, 2);
});

it('queues the listener and the delivery job after commit on the default queue', function () {
    $listener = app(NotifyTelegramCustomer::class);
    $job = new DeliverOrderStatusMessage(1, 2);

    expect($listener)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($listener->queue)->toBe('default')
        ->and($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->queue)->toBe('default')
        ->and($job->backoff())->toBe([10, 60, 300, 900])
        ->and($job->maxExceptions)->toBe(5);
});

it('opens the order from the status button start_param (own order) in the Mini App', function () {
    $user = tmaUser(['telegram_id' => 5550401]);
    $order = Order::factory()->telegram($user)->create();
    $link = BotUsername::startAppLink('o'.$order->id);
    parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

    expect(TmaTarget::isValid($query['startapp']))->toBeTrue();

    $this->withoutVite();
    tmaAuth(tmaInitData(5550401, ['start_param' => $query['startapp']]))
        ->assertOk()
        ->assertJson(['redirect' => route('tma.orders.show', ['locale' => $user->locale ?? 'uz', 'order' => $order->id])]);
});

it('keeps DeliverBotMessage plain text without any button', function () {
    $user = tmaUser(['telegram_id' => 5550501]);
    $message = TelegramMessage::query()->create([
        'telegram_user_id' => $user->id,
        'direction' => TelegramDirection::Out,
        'kind' => TelegramMessageKind::Text,
        'text' => 'Staff reply',
        'status' => TelegramMessageStatus::Pending,
    ]);

    (new DeliverBotMessage($message->id))->handle(app(TelegramClient::class));

    expect(m5Calls('sendMessage')[0]['data'])->not->toHaveKey('reply_markup')->not->toHaveKey('parse_mode');
});

it('refuses web_app markup outside WebAppButton and URLs off the canonical Mini App', function (string $url) {
    expect(WebAppButton::isAllowedUrl($url))->toBeFalse()
        ->and(fn () => WebAppButton::make('Open', $url))->toThrow(InvalidArgumentException::class);
})->with([
    'http' => 'http://poson.example.uz/tma',
    'other host' => 'https://evil.example/tma',
    'prefix trick' => 'https://poson.example.uz/tmaevil',
    'host suffix' => 'https://poson.example.uz.evil.example/tma',
    'credentials' => 'https://x@poson.example.uz/tma',
    'site page' => 'https://poson.example.uz/uz',
    'dot segments' => 'https://poson.example.uz/tma/../admin',
    'fragment' => 'https://poson.example.uz/tma#x',
    'port' => 'https://poson.example.uz:8443/tma',
]);

it('keeps the new bot copy brand-safe in every locale', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach (['menu.open_app', 'menu.open_catalog', 'menu.open_app_reply', 'order_status.button'] as $key) {
            expect(BrandSafeText::violates(__('telegram_bot.'.$key, [], $locale)))->toBeFalse();
        }

        foreach (OrderStatus::cases() as $status) {
            expect(BrandSafeText::violates(__('telegram_bot.order_status.text', ['number' => 'O-2026-0001', 'status' => __('enums.order_status.'.$status->value, [], $locale)], $locale)))->toBeFalse();
        }
    }
});
