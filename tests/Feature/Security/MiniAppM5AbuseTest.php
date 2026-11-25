<?php

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\QueueOrderStatusMessage;
use App\Actions\Telegram\RegisterTelegramUser;
use App\Actions\Telegram\ResendTelegramMessage;
use App\Actions\Telegram\SetConversationBan;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Events\Orders\OrderStatusChanged;
use App\Jobs\DeliverBotMessage;
use App\Jobs\DeliverOrderStatusMessage;
use App\Jobs\SendBotPrompt;
use App\Jobs\SyncMiniAppMenuButton;
use App\Listeners\NotifyTelegramCustomer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WebAppButton;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\MiniApp\BotUsername;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Helpers/MiniApp.php';
require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 20 M5 security refresh (§21): abuse cases 1-16, one test (or
| dataset) each. In-process, test DB only, fake token, Http::fake only.
*/
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 91, 'username' => 'poson_test_bot']])]);
    tmaEnabled();
    BotUsername::remember('poson_test_bot');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/**
 * @return list<array<string, mixed>>
 */
/**
 * Replace (not stack) the Http fake: Http::fake() keeps earlier stubs first.
 */
function m5AbuseFake(mixed $fake): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($fake);
}

function m5AbuseSent(string $method = 'sendMessage'): array
{
    return array_values(array_map(fn (array $call): array => $call['data'], array_filter(telegramApiCalls(), fn (array $call): bool => $call['method'] === $method)));
}

function m5AbuseOrder(?TelegramUser $user = null, array $attributes = []): Order
{
    return Order::factory()->telegram($user ?? tmaUser())->create($attributes);
}

// 1
it('routes start_param o<foreign id> to the own orders list and never shows the foreign order', function () {
    $me = tmaUser(['telegram_id' => 5551001, 'locale' => 'uz']);
    $foreign = m5AbuseOrder(tmaUser(), ['number' => 'O-2026-9001']);

    tmaAuth(tmaInitData(5551001, ['start_param' => 'o'.$foreign->id]))
        ->assertOk()
        ->assertJson(['redirect' => route('tma.orders', ['locale' => 'uz'])]);

    $this->get(route('tma.orders', ['locale' => 'uz']))->assertOk()->assertDontSee('O-2026-9001');
    $this->get(route('tma.orders.show', ['locale' => 'uz', 'order' => $foreign->id]))->assertNotFound();
    expect($me->fresh())->not->toBeNull();
});

// 2
it('sends a malformed start_param to the home screen', function (string $startParam) {
    tmaUser(['telegram_id' => 5551002, 'locale' => 'uz']);
    $own = m5AbuseOrder(TelegramUser::query()->where('telegram_id', 5551002)->sole());

    tmaAuth(tmaInitData(5551002, ['start_param' => str_replace('{id}', (string) $own->id, $startParam)]))
        ->assertOk()
        ->assertJson(['redirect' => route('tma.home', ['locale' => 'uz'])]);
})->with([
    'trailing newline' => "o{id}\n",
    'leading zero' => 'o0{id}',
    'eleven digits' => 'o99999999999',
    'upper case' => 'O{id}',
    'query smuggling' => 'o{id}&x=1',
    'path traversal' => '../',
    'javascript scheme' => 'javascript:alert(1)',
    'encoded dot' => '%2e',
]);

// 3
it('routes next=o<foreign id> like case 1', function () {
    tmaUser(['telegram_id' => 5551003, 'locale' => 'uz']);
    $foreign = m5AbuseOrder(tmaUser());

    tmaAuth(tmaInitData(5551003), next: 'o'.$foreign->id)
        ->assertOk()
        ->assertJson(['redirect' => route('tma.orders', ['locale' => 'uz'])]);
});

// 4
it('never answers a business message "Catalog" or syncs a menu button for it', function () {
    Queue::fake([SendBotPrompt::class, SyncMiniAppMenuButton::class, DeliverOrderStatusMessage::class]);
    tmaUser(['telegram_id' => 9990010001]);

    $payload = officeFixture('message_client', 7001, ['business_message' => ['text' => __('telegram_bot.menu.catalog', [], 'uz')]]);

    expect(app(HandleTelegramUpdate::class)->handle($payload))->toBe('business');

    Queue::assertNotPushed(SendBotPrompt::class);
    Queue::assertNotPushed(SyncMiniAppMenuButton::class);
    expect(m5AbuseSent())->toBe([])->and(m5AbuseSent('setChatMenuButton'))->toBe([]);
});

// 5
it('stores and sends one message when the listener runs twice or the job is retried', function () {
    $order = m5AbuseOrder();
    $event = new OrderStatusChanged($order, OrderStatus::New, OrderStatus::Confirmed);
    $listener = app(NotifyTelegramCustomer::class);

    $listener->handle($event);
    $listener->handle($event);

    $row = TelegramMessage::query()->where('order_id', $order->id)->sole();
    $job = new DeliverOrderStatusMessage($row->id, $order->id);
    $job->handle(app(TelegramClient::class));
    $job->handle(app(TelegramClient::class));

    expect(m5AbuseSent())->toHaveCount(1)
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and(fn () => TelegramMessage::query()->insert([
            'telegram_user_id' => $row->telegram_user_id, 'direction' => 'out', 'kind' => 'text', 'text' => 'x', 'status' => 'pending',
            'order_id' => $order->id, 'order_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
});

// 6
it('sends exactly one "cancelled" without the reason when a refund cancels the order', function () {
    $order = Order::factory()->telegram(tmaUser(['telegram_id' => 5551006]))->bankTransfer()->paid()->totalling(100_00)->status(OrderStatus::Confirmed)->create();
    $payment = OrderPayment::factory()->for($order)->create(['amount' => 100_00]);

    app(RecordOrderRefund::class)->handle($order, $payment, 'Secret refund reason XYZ', $this->admin);

    $rows = TelegramMessage::query()->where('order_id', $order->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->order_status)->toBe(OrderStatus::Cancelled)
        ->and($rows->first()->text)->not->toContain('XYZ')
        ->and(collect(m5AbuseSent())->where('chat_id', '5551006')->values()->all())->toHaveCount(1)
        ->and(json_encode(collect(m5AbuseSent())->where('chat_id', '5551006')->all()))->not->toContain('XYZ');
});

it('sends one "cancelled" without the reason when staff cancel with a reason', function () {
    $order = Order::factory()->telegram(tmaUser(['telegram_id' => 5551016]))->create();

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->admin, 'Secret cancel reason QWE');

    $rows = TelegramMessage::query()->where('order_id', $order->id)->get();
    $sent = collect(m5AbuseSent())->where('chat_id', '5551016')->values();

    expect($order->fresh()->cancel_reason)->toContain('QWE')
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->order_status)->toBe(OrderStatus::Cancelled)
        ->and($rows->first()->text)->not->toContain('QWE')
        ->and($sent)->toHaveCount(1)
        ->and(json_encode($sent->all()))->not->toContain('QWE');
});

// 7
it('announces nothing for a status change in a rolled-back transaction', function () {
    $order = m5AbuseOrder();

    try {
        DB::transaction(function () use ($order): void {
            app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($order->fresh()->status)->toBe(OrderStatus::New)
        ->and(TelegramMessage::query()->whereNotNull('order_id')->count())->toBe(0)
        ->and(m5AbuseSent())->toBe([]);
});

// 8
it('sends no status message to banned, blocked or switched-off customers or for test orders', function (Closure $setup) {
    $user = tmaUser();
    $order = Order::factory()->telegram($user)->create();
    $order = $setup($user, $order) ?? $order;

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    expect(m5AbuseSent())->toBe([])
        ->and(TelegramMessage::query()->where('status', '!=', TelegramMessageStatus::Skipped)->whereNotNull('order_id')->count())->toBe(0);
})->with([
    'banned' => [fn (TelegramUser $user) => $user->forceFill(['banned_at' => now()])->save() ? null : null],
    'blocked' => [fn (TelegramUser $user) => $user->forceFill(['blocked_at' => now()])->save() ? null : null],
    'Mini App off' => [fn () => tmaEnabled(miniApp: false)],
    'bot off' => [fn () => tmaEnabled(bot: false)],
    'test order' => [fn (TelegramUser $user, Order $order) => tap($order)->forceFill(['is_test' => true])->save() ? $order->fresh() : null],
]);

it('falls back to menu.catalog_reply for the inline catalog outside the audience', function (Closure $setup) {
    $user = tmaUser(['telegram_id' => 5551008, 'locale' => 'en']);
    $setup($user);

    (new SendBotPrompt($user->id, ['menu.open_app_reply'], BotKeyboards::OPEN_APP))->handle(app(TelegramClient::class));

    $sent = m5AbuseSent()[0];

    expect($sent['text'])->toBe(__('telegram_bot.menu.catalog_reply', ['url' => route('site.catalog.index', ['locale' => 'en'])], 'en'))
        ->and(json_encode($sent))->not->toContain('web_app');
})->with([
    'blocked' => [fn (TelegramUser $user) => $user->forceFill(['blocked_at' => now()])->save()],
    'Mini App off' => [fn () => tmaEnabled(miniApp: false)],
    'not registered' => [fn (TelegramUser $user) => $user->forceFill(['registered_at' => null])->save()],
]);

it('sends nothing at all to a banned user for the inline catalog', function () {
    $user = tmaUser(['banned_at' => now()]);

    (new SendBotPrompt($user->id, ['menu.open_app_reply'], BotKeyboards::OPEN_APP))->handle(app(TelegramClient::class));

    expect(m5AbuseSent())->toBe([]);
});

// 9
it('re-checks at send time: banned, switch off or order deleted after dispatch → skipped / fallback', function (Closure $change) {
    Queue::fake([DeliverOrderStatusMessage::class]);
    $user = tmaUser();
    $order = m5AbuseOrder($user);

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    $job = null;
    Queue::assertPushed(DeliverOrderStatusMessage::class, function (DeliverOrderStatusMessage $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $change($user, $order);
    $job->handle(app(TelegramClient::class));

    expect(TelegramMessage::query()->find($job->telegramMessageId)->status)->toBe(TelegramMessageStatus::Skipped)
        ->and(m5AbuseSent())->toBe([]);
})->with([
    'banned' => [fn (TelegramUser $user) => $user->forceFill(['banned_at' => now()])->save()],
    'blocked' => [fn (TelegramUser $user) => $user->forceFill(['blocked_at' => now()])->save()],
    'Mini App off' => [fn () => tmaEnabled(miniApp: false)],
    'order deleted' => [fn (TelegramUser $user, Order $order) => $order->delete()],
    'became a test order' => [fn (TelegramUser $user, Order $order) => $order->forceFill(['is_test' => true])->save()],
]);

it('falls back for the inline catalog when the user is banned or the switch goes off after dispatch', function () {
    Queue::fake([SendBotPrompt::class]);
    tmaUser(['telegram_id' => 5550001, 'locale' => 'uz']);

    postTelegramUpdate(telegramPrivateUpdate(9301, ['text' => __('telegram_bot.menu.catalog', [], 'uz')]))->assertOk();

    $job = null;
    Queue::assertPushed(SendBotPrompt::class, function (SendBotPrompt $pushed) use (&$job): bool {
        $job = $pushed;

        return $pushed->keyboard === BotKeyboards::OPEN_APP;
    });

    tmaEnabled(miniApp: false);
    $job->handle(app(TelegramClient::class));

    expect(m5AbuseSent()[0]['text'])->toBe(__('telegram_bot.menu.catalog_reply', ['url' => route('site.catalog.index', ['locale' => 'uz'])], 'uz'))
        ->and(json_encode(m5AbuseSent()))->not->toContain('web_app');
});

// 10
it('sends plain text without a button when the stored bot username is invalid', function (string $username) {
    Setting::query()->updateOrCreate(['key' => BotUsername::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $username]);
    $order = m5AbuseOrder();

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    expect(m5AbuseSent())->toHaveCount(1)
        ->and(m5AbuseSent()[0])->not->toHaveKey('reply_markup')
        ->and(json_encode(m5AbuseSent()))->not->toContain('t.me');
})->with(['"/../', 'evil.example/x', 'ab', 'bot name', "poson_bot\n"]);

// 11
it('never builds a web_app button from a forged host or an http APP_URL', function () {
    $user = tmaUser(['telegram_id' => 5551011, 'locale' => 'en']);

    URL::forceRootUrl('https://evil.example');
    (new SendBotPrompt($user->id, ['menu.open_app_reply'], BotKeyboards::OPEN_APP))->handle(app(TelegramClient::class));
    (new SyncMiniAppMenuButton($user->id))->handle(app(TelegramClient::class));
    URL::forceRootUrl(null);

    expect(json_encode(telegramApiCalls()))->not->toContain('evil.example')
        ->and(m5AbuseSent()[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://poson.example.uz/tma')
        ->and(m5AbuseSent('setChatMenuButton')[0]['menu_button']['web_app']['url'])->toBe('https://poson.example.uz/tma');

    m5AbuseFake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 92]])]);
    config(['app.url' => 'http://poson.example.uz']);

    (new SendBotPrompt($user->id, ['menu.open_app_reply'], BotKeyboards::OPEN_APP))->handle(app(TelegramClient::class));
    (new SyncMiniAppMenuButton($user->id))->handle(app(TelegramClient::class));

    expect(json_encode(m5AbuseSent()))->not->toContain('web_app')
        ->and(m5AbuseSent('setChatMenuButton')[0]['menu_button'])->toBe(['type' => 'default'])
        ->and(WebAppButton::miniApp('Open'))->toBeNull();
});

it('builds the web_app button on APP_URL for a webhook request with a forged Host header', function () {
    tmaUser(['telegram_id' => 5550001, 'locale' => 'uz']);
    $content = json_encode(telegramPrivateUpdate(9111, ['text' => __('telegram_bot.menu.catalog', [], 'uz')]));

    $response = $this->call('POST', 'https://evil.example'.route('api.telegram.webhook', [], false), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_HOST' => 'evil.example',
        'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => TG_WEBHOOK_SECRET,
    ], $content);

    $response->assertOk();

    expect(json_encode(telegramApiCalls()))->not->toContain('evil.example')
        ->and(m5AbuseSent())->toHaveCount(1)
        ->and(m5AbuseSent()[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://poson.example.uz/tma');
});

// 12
it('releases a 429 for retry_after, marks 403 blocked and carries on with the others in --all', function () {
    $limited = tmaUser(['telegram_id' => 5551201]);
    $blocked = tmaUser(['telegram_id' => 5551202]);
    $fine = tmaUser(['telegram_id' => 5551203]);

    m5AbuseFake(function (Request $request) {
        if (str_ends_with($request->url(), '/getMe')) {
            return Http::response(['ok' => true, 'result' => ['username' => 'poson_test_bot']]);
        }

        return match ((string) ($request->data()['chat_id'] ?? '')) {
            '5551201' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 7]], 429),
            '5551202' => Http::response(['ok' => false, 'error_code' => 403], 403),
            default => Http::response(['ok' => true, 'result' => true]),
        };
    });

    $this->artisan('telegram:mini-app-menu', ['--all' => true])->assertSuccessful();

    $job = (new SyncMiniAppMenuButton($limited->id))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));
    $job->assertReleased(delay: 7);
    $job->assertNotFailed();

    expect($blocked->fresh()->blocked_at)->not->toBeNull()
        ->and($limited->fresh()->blocked_at)->toBeNull()
        ->and(collect(m5AbuseSent('setChatMenuButton'))->pluck('chat_id')->all())->toContain('5551201', '5551202', '5551203')
        ->and(collect(m5AbuseSent('setChatMenuButton'))->firstWhere('chat_id', '5551203')['menu_button']['type'])->toBe('web_app')
        ->and($fine->fresh()->blocked_at)->toBeNull();
});

it('marks the user blocked without retry on 403 from a status message', function () {
    m5AbuseFake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403], 403)]);
    $user = tmaUser();
    $order = m5AbuseOrder($user);

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    expect($user->fresh()->blocked_at)->not->toBeNull()
        ->and(TelegramMessage::query()->where('order_id', $order->id)->value('status'))->toBe(TelegramMessageStatus::Failed);
});

// 13
it('keeps the status payload free of the ULID, amount, address, STIR and name, with exactly one URL button', function () {
    $user = tmaUser(['telegram_id' => 5551301]);
    $order = Order::factory()->telegram($user)->company('305555555', 'Maxfiy Savdo MChJ')->totalling(7_654_321_00)->create([
        'name' => 'Ismi Sharifov', 'address' => 'Maxfiy koʻcha 99', 'number' => 'O-2026-1301',
    ]);

    app(ChangeOrderStatus::class)->handle($order, OrderStatus::Confirmed, $this->admin);

    $sent = m5AbuseSent()[0];
    $payload = json_encode($sent, JSON_UNESCAPED_UNICODE);
    $buttons = collect($sent['reply_markup']['inline_keyboard'])->flatten(1);

    expect($sent['chat_id'])->toBe('5551301')
        ->and($payload)->toContain('O-2026-1301')
        ->not->toContain((string) $order->ulid)
        ->not->toContain('7654321')->not->toContain('7 654 321')
        ->not->toContain('Maxfiy')->not->toContain('305555555')->not->toContain('Ismi')
        ->and($buttons)->toHaveCount(1)
        ->and(array_keys($buttons->first()))->toBe(['text', 'url'])
        ->and($buttons->first()['url'])->toBe('https://t.me/poson_test_bot?startapp=o'.$order->id)
        ->and(serialize(new DeliverOrderStatusMessage(1, $order->id)))->not->toContain('O-2026-1301')->not->toContain('5551301');

    m5AbuseFake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 93]])]);
    $reply = TelegramMessage::query()->create([
        'telegram_user_id' => $user->id, 'direction' => 'out', 'kind' => 'text', 'text' => 'Reply', 'status' => TelegramMessageStatus::Pending,
    ]);
    (new DeliverBotMessage($reply->id))->handle(app(TelegramClient::class));

    expect(m5AbuseSent()[0])->not->toHaveKey('reply_markup');
});

it('never delivers a status row through DeliverBotMessage', function () {
    $user = tmaUser();
    $row = TelegramMessage::query()->create([
        'telegram_user_id' => $user->id, 'direction' => 'out', 'kind' => 'text', 'text' => 'Status', 'status' => TelegramMessageStatus::Pending,
    ]);
    $row->forceFill(['order_id' => m5AbuseOrder($user)->id, 'order_status' => OrderStatus::Confirmed])->save();

    (new DeliverBotMessage($row->id))->handle(app(TelegramClient::class));

    expect(m5AbuseSent())->toBe([])->and($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

// 14
it('refuses an admin resend of a failed status row', function () {
    $user = tmaUser();
    $row = TelegramMessage::query()->create([
        'telegram_user_id' => $user->id, 'direction' => 'out', 'kind' => 'text', 'text' => 'Status', 'status' => TelegramMessageStatus::Failed,
    ]);
    $row->forceFill(['order_id' => m5AbuseOrder($user)->id, 'order_status' => OrderStatus::Shipped, 'user_id' => $this->admin->id])->save();

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, $this->admin))->toThrow(InboxRefused::class)
        ->and($row->fresh()->isResendable())->toBeFalse()
        ->and(TelegramMessage::query()->resendable()->whereKey($row->id)->exists())->toBeFalse()
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and(m5AbuseSent())->toBe([]);
});

// 15
it('puts every new job and the listener on the default queue', function () {
    Queue::fake([SyncMiniAppMenuButton::class, DeliverOrderStatusMessage::class, SendBotPrompt::class]);
    $user = tmaUser(['telegram_id' => 5550001]);

    app(QueueOrderStatusMessage::class)->handle(m5AbuseOrder($user)->id, OrderStatus::Confirmed);
    $this->artisan('telegram:mini-app-menu', ['--chat' => '5550001'])->assertSuccessful();
    postTelegramUpdate(telegramPrivateUpdate(9501, ['text' => __('telegram_bot.menu.catalog', [], 'uz')]))->assertOk();

    Queue::assertPushedOn('default', DeliverOrderStatusMessage::class);
    Queue::assertPushedOn('default', SyncMiniAppMenuButton::class);
    Queue::assertPushedOn('default', SendBotPrompt::class);
    expect(app(NotifyTelegramCustomer::class)->queue)->toBe('default');
});

// 16
it('never sets a web_app button as the bot-wide default', function () {
    tmaUser();

    $this->artisan('telegram:mini-app-menu', ['--reset' => true])->assertSuccessful();

    expect(m5AbuseSent('setChatMenuButton'))->toBe([['menu_button' => ['type' => 'default']]])
        ->and(fn () => app(TelegramClient::class)->setChatMenuButton(null, WebAppButton::make('Open', 'https://poson.example.uz/tma')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(TelegramClient::class)->sendMessage('1', 'x', [], ['inline_keyboard' => [[['text' => 'x', 'web_app' => ['url' => 'https://evil.example']]]]]))->toThrow(InvalidArgumentException::class)
        ->and(m5AbuseSent('setChatMenuButton'))->toHaveCount(1)
        ->and(m5AbuseSent())->toBe([]);
});

// Dispatch points of the menu sync (plan 20 §7).
it('queues the menu sync when a customer registers', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = TelegramUser::factory()->create(['state' => TelegramUserState::Inn, 'phone' => '+998901234567', 'name' => 'Ali', 'consent_at' => now()]);

    app(RegisterTelegramUser::class)->handle($user);

    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && ! $job->reset);
});

it('queues the menu sync when a registered customer changes language', function () {
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = tmaUser(['telegram_id' => 5550001]);

    postTelegramUpdate(['update_id' => 9601, 'callback_query' => ['id' => 'cb6', 'data' => 'lang:ru', 'from' => ['id' => 5550001, 'is_bot' => false, 'first_name' => 'Ali'], 'message' => ['message_id' => 6, 'chat' => ['id' => 5550001, 'type' => 'private']]]])->assertOk();

    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && ! $job->reset);
});

it('queues a reset on ban and a sync on unban, even with the Mini App switch off', function () {
    tmaEnabled(miniApp: false);
    Queue::fake([SyncMiniAppMenuButton::class]);
    $user = tmaUser();

    app(SetConversationBan::class)->ban($user, $this->admin, null);
    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && $job->reset);

    app(SetConversationBan::class)->unban($user->fresh(), $this->admin, null);
    Queue::assertPushed(SyncMiniAppMenuButton::class, fn (SyncMiniAppMenuButton $job): bool => $job->telegramUserId === $user->id && ! $job->reset);
    Queue::assertPushed(SyncMiniAppMenuButton::class, 2);
});
