<?php

require_once __DIR__.'/ChatBotHelpers.php';

use App\Actions\Chat\LookupOrderStatus;
use App\Actions\Chat\LookupRepairStatus;
use App\Enums\ChatChannel;
use App\Enums\OrderStatus;
use App\Enums\RepairStatus;
use App\Jobs\SendChatLookupFailureAlert;
use App\Models\Order;
use App\Models\Repair;
use App\Models\TelegramUser;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\LookupNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C3 / C3b: order and repair status in the Telegram bot (CB-15, CB-16,
 * CB-28 to CB-30): the gate, the one scoped query, identical misses, limits
 * before the query, the failure alert, Telegram only, nothing "verified"
 * remembered; LookupNumber parsing.
 */
const CB_PHONE = '+998901234567';

beforeEach(fn () => cbSetup());

it('parses order and repair numbers', function (string $text, ?array $expected) {
    expect(LookupNumber::parse($text))->toBe($expected);
})->with([
    'order' => ['O-2026-0001', ['kind' => 'order', 'number' => 'O-2026-0001']],
    'repair' => ['R-2026-000123', ['kind' => 'repair', 'number' => 'R-2026-000123']],
    'lower case with spaces' => ['o 2026 0001', ['kind' => 'order', 'number' => 'O-2026-0001']],
    'hash and no separators' => ['#O20260001', ['kind' => 'order', 'number' => 'O-2026-0001']],
    'cyrillic O' => ['О-2026-0001', ['kind' => 'order', 'number' => 'O-2026-0001']],
    'cyrillic R' => ['р-2026-000123', ['kind' => 'repair', 'number' => 'R-2026-000123']],
    'surrounding spaces' => ['  O-2026-0001  ', ['kind' => 'order', 'number' => 'O-2026-0001']],
    'test order number' => ['T-2026-0001', null],
    'trailing newline text' => ["O-2026-0001\nplease", null],
    'two digits short' => ['O-2026-12', null],
    'too many digits' => ['R-2026-1234567', null],
    'a sentence' => ['where is O-2026-0001', null],
    'empty' => ['', null],
]);

it('parses a number followed by a newline only after trimming, never as a longer message', function () {
    expect(LookupNumber::parse("O-2026-0001\n"))->toBe(['kind' => 'order', 'number' => 'O-2026-0001'])
        ->and(LookupNumber::parse("O-2026-0001\n\nx"))->toBeNull()
        ->and(LookupNumber::parse(str_repeat('O', 500)))->toBeNull()
        ->and(LookupNumber::parse(null))->toBeNull();
});

it('finds an own order by its typed number and shows only the status', function () {
    cbUser();
    $order = Order::factory()->forPhone(CB_PHONE)->status(OrderStatus::Processing)->paid()->create(['number' => 'O-2026-0001', 'name' => 'Secret Buyer', 'total' => 987_654_300]);

    cbText('o 2026 0001');

    expect(cbLast())->toContain('O-2026-0001')
        ->toContain(e(__('enums.order_status.processing', [], 'en')))
        ->toContain(e(__('enums.payment_status.paid', [], 'en')))
        ->not->toContain('Secret Buyer')
        ->not->toContain('9876543')
        ->and(DB::table('chat_unmatched')->count())->toBe(0);
});

it('gives one identical reply for a wrong number, another customers order, a test order and an old order', function (callable $arrange) {
    cbUser();
    $arrange();
    $missing = e(__('chatbot.lookup.order.not_found', [], 'en'));

    cbText('O-2026-0001');

    expect(cbLast())->toBe($missing);
})->with([
    'unknown number' => [fn () => Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0099'])],
    'other customer' => [fn () => Order::factory()->forPhone('+998977654321')->create(['number' => 'O-2026-0001'])],
    'test order' => [fn () => Order::factory()->test()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001'])],
    'older than 12 months' => [fn () => Order::factory()->forPhone(CB_PHONE)->placedMonthsAgo(13)->create(['number' => 'O-2026-0001'])],
]);

it('finds an order placed inside the 12 month window', function () {
    cbUser();
    Order::factory()->forPhone(CB_PHONE)->placedMonthsAgo(11)->create(['number' => 'O-2026-0001']);

    cbText('O-2026-0001');

    expect(cbLast())->toContain('O-2026-0001')->not->toContain(e(__('chatbot.lookup.order.not_found', [], 'en')));
});

it('finds a repair by contact phone, open or closed within 90 days, and hides the rest', function (callable $make, bool $found) {
    cbUser();
    $make();

    cbText('R-2026-000123');

    expect(cbLast() === e(__('chatbot.lookup.repair.not_found', [], 'en')))->toBe(! $found);
    if ($found) {
        expect(cbLast())->toContain('R-2026-000123');
    }
})->with([
    'open' => [fn () => Repair::factory()->forPhone(CB_PHONE)->create(['number' => 'R-2026-000123']), true],
    'closed 89 days ago' => [fn () => Repair::factory()->forPhone(CB_PHONE)->closedDaysAgo(89)->create(['number' => 'R-2026-000123']), true],
    'closed 91 days ago' => [fn () => Repair::factory()->forPhone(CB_PHONE)->closedDaysAgo(91)->create(['number' => 'R-2026-000123']), false],
    'other phone' => [fn () => Repair::factory()->forPhone('+998977654321')->create(['number' => 'R-2026-000123']), false],
    'unknown number' => [fn () => Repair::factory()->forPhone(CB_PHONE)->create(['number' => 'R-2026-000999']), false],
]);

it('never puts repair intake details into the reply', function () {
    cbUser();
    Repair::factory()->forPhone(CB_PHONE)->status(RepairStatus::AwaitingApproval)->estimated()->create([
        'number' => 'R-2026-000123', 'serial_number' => 'SERIAL-XYZ-1', 'problem' => 'Secret problem text', 'contact_name' => 'Secret Name',
    ]);

    cbText('R-2026-000123');

    expect(cbLast())->toContain('R-2026-000123')
        ->toContain('Zebra')
        ->toContain(e(__('chatbot.lookup.repair.awaiting_approval', [], 'en')))
        ->not->toContain('SERIAL-XYZ-1')
        ->not->toContain('Secret problem text')
        ->not->toContain('Secret Name')
        ->not->toContain('150000');
});

it('offers a manager and runs no order or repair query without a usable registered phone', function (string $state, string $text) {
    $user = match ($state) {
        'anonymous' => TelegramUser::factory()->anonymousNumber()->create(['telegram_id' => CB_USER, 'locale' => 'en']),
        'unregistered' => TelegramUser::factory()->unregistered()->create(['telegram_id' => CB_USER, 'locale' => 'en']),
        'banned' => TelegramUser::factory()->registered()->banned()->create(['telegram_id' => CB_USER, 'locale' => 'en']),
    };
    $reply = fn (string $number) => $number === 'O' ? app(LookupOrderStatus::class)->byNumber($user, $text, 'en') : app(LookupRepairStatus::class)->byNumber($user, $text, 'en');

    DB::enableQueryLog();
    $order = $reply('O');
    $repair = $reply('R');
    $log = DB::getQueryLog();

    expect($order->text)->toBe(__('chatbot.lookup.unavailable', [], 'en'))
        ->and($repair->text)->toBe(__('chatbot.lookup.unavailable', [], 'en'))
        ->and(cbQueriesLike($log, 'from "orders"'))->toBe(0)
        ->and(cbQueriesLike($log, 'from "repairs"'))->toBe(0)
        ->and(collect($order->buttons)->pluck('type')->all())->toBe(['handoff', 'menu']);
})->with(['anonymous' => ['anonymous', 'O-2026-0001'], 'unregistered' => ['unregistered', 'O-2026-0001'], 'banned' => ['banned', 'O-2026-0001']]);

it('refuses a +888 customer end to end with zero order queries', function () {
    TelegramUser::factory()->anonymousNumber()->create(['telegram_id' => CB_USER, 'locale' => 'en', 'chat_mode' => 'bot']);
    Order::factory()->forPhone('+88800001234567')->create(['number' => 'O-2026-0001']);

    DB::enableQueryLog();
    cbText('O-2026-0001');
    $log = DB::getQueryLog();

    expect(cbLast())->toBe(e(__('chatbot.lookup.unavailable', [], 'en')))
        ->and(cbQueriesLike($log, 'from "orders"'))->toBe(0);
});

it('does not let a newer registrant of the same phone inherit nor lose the history wrongly', function () {
    $old = cbUser(['phone' => CB_PHONE, 'registered_at' => now()->subDays(10)], CB_USER);
    cbUser(['phone' => CB_PHONE, 'registered_at' => now()->subDay()], CB_USER + 1);
    Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001']);

    DB::enableQueryLog();
    cbText('O-2026-0001', CB_USER);
    $oldLog = DB::getQueryLog();
    $oldReply = cbLast();
    DB::flushQueryLog();
    cbText('O-2026-0001', CB_USER + 1);

    expect($oldReply)->toBe(e(__('chatbot.lookup.unavailable', [], 'en')))
        ->and(cbQueriesLike($oldLog, 'from "orders"'))->toBe(0)
        ->and(cbLast())->toContain('O-2026-0001')
        ->and($old->fresh()->phone)->toBe(CB_PHONE);
});

it('limits lookups per customer before the query', function () {
    config(['chatbot.lookup.per_hour_user' => 3]);
    cbUser();
    Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001']);

    foreach (range(1, 3) as $ignored) {
        cbText('O-2026-0001');
    }

    DB::enableQueryLog();
    cbText('O-2026-0001');
    $log = DB::getQueryLog();

    expect(cbLast())->toBe(e(__('chatbot.lookup.limited', [], 'en')))
        ->and(cbQueriesLike($log, 'from "orders"'))->toBe(0);
});

it('limits lookups over all chats before the query', function () {
    config(['chatbot.lookup.per_hour_global' => 2]);
    foreach ([0, 1, 2] as $n) {
        cbUser(['phone' => '+99890123456'.$n], CB_USER + $n);
    }

    cbText('R-2026-000123', CB_USER);
    cbText('R-2026-000123', CB_USER + 1);

    DB::enableQueryLog();
    cbText('R-2026-000123', CB_USER + 2);
    $log = DB::getQueryLog();

    expect(cbLast())->toBe(e(__('chatbot.lookup.limited', [], 'en')))
        ->and(cbQueriesLike($log, 'from "repairs"'))->toBe(0);
});

it('raises one counts-only alert when a number fails five times in 24 hours', function () {
    Queue::fake([SendChatLookupFailureAlert::class]);
    $user = cbUser(['phone' => CB_PHONE]);

    foreach (range(1, 4) as $ignored) {
        cbText('O-2026-0777');
    }

    Queue::assertNothingPushed();

    RateLimiter::clear('chat-lookup:user:'.$user->id);
    cbText('O-2026-0777');
    cbText('O-2026-0777');

    Queue::assertPushed(SendChatLookupFailureAlert::class, 1);
    Queue::assertPushed(SendChatLookupFailureAlert::class, function (SendChatLookupFailureAlert $job): bool {
        $payload = json_encode(['job' => $job, 'serialized' => serialize($job)]);

        return $job->failures === 5 && ! str_contains($payload, '0777') && ! str_contains($payload, '901234567');
    });
});

it('throttles the failure alert to one an hour across numbers', function () {
    Queue::fake([SendChatLookupFailureAlert::class]);
    config(['chatbot.lookup.failures_alert' => 1, 'chatbot.lookup.per_hour_user' => 50]);
    cbUser();

    cbText('O-2026-0101');
    cbText('O-2026-0102');
    cbText('R-2026-000103');

    Queue::assertPushed(SendChatLookupFailureAlert::class, 1);
});

it('does not count a found order as a failure', function () {
    Queue::fake([SendChatLookupFailureAlert::class]);
    config(['chatbot.lookup.failures_alert' => 1]);
    cbUser();
    Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001']);

    cbText('O-2026-0001');

    Queue::assertNothingPushed();
});

it('answers the web channel with a Telegram-only reply and runs no query', function (string $text, string $kind) {
    $reply = null;

    DB::enableQueryLog();
    $reply = app(Engine::class)->respond(new ChatContext(ChatChannel::Web, 'en', $text));
    $log = DB::getQueryLog();

    expect($reply->text)->toBe(__("chatbot.lookup.web.{$kind}", [], 'en'))
        ->and($reply->outcome)->not->toBe(ChatReply::ORDER_STATUS)
        ->and($reply->outcome)->not->toBe(ChatReply::REPAIR_STATUS)
        ->and($reply->param)->toBeNull()
        ->and(cbQueriesLike($log, 'from "orders"'))->toBe(0)
        ->and(cbQueriesLike($log, 'from "repairs"'))->toBe(0);
})->with([['O-2026-0001', 'order'], ['R-2026-000123', 'repair']]);

it('remembers nothing verified between messages', function () {
    $user = cbUser();
    Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001']);

    cbText('O-2026-0001');
    $dialog = json_encode($user->fresh()->bot_dialog);

    expect($dialog)->not->toContain('verified')->not->toContain('O-2026-0001')->not->toContain('901234567')
        ->and(array_keys($user->fresh()->bot_dialog ?? []))->each->toBeIn(['rules', 'buttons', 'gen', 'at'])
        ->and($user->fresh()->link_verified_at)->toBeNull();

    // The same customer losing the phone gate loses the access at once.
    $user->forceFill(['phone' => '+88800001234567'])->save();
    cbText('O-2026-0001');

    expect(cbLast())->toBe(e(__('chatbot.lookup.unavailable', [], 'en')));
});

it('lists the customers own latest orders and repairs and opens one through its stored button', function (string $kind) {
    $user = cbUser();
    $mine = $kind === 'order'
        ? Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001'])
        : Repair::factory()->forPhone(CB_PHONE)->create(['number' => 'R-2026-000123']);
    $kind === 'order'
        ? Order::factory()->forPhone('+998977654321')->create(['number' => 'O-2026-0002'])
        : Repair::factory()->forPhone('+998977654321')->create(['number' => 'R-2026-000124']);

    cbText(__("telegram_bot.menu.{$kind}_status", [], 'en'));

    expect(cbLast())->toBe(e(__("chatbot.lookup.{$kind}.recent", [], 'en')))
        ->and($user->fresh()->bot_dialog['buttons'])->toBe([0 => ['t' => $kind, 'id' => $mine->id]]);

    cbPress(cbButton($user, $kind));

    expect(cbLast())->toContain($mine->number)->not->toContain('0002')->not->toContain('000124');
})->with(['order', 'repair']);

it('caps the recent list at five items', function () {
    $user = cbUser();
    Order::factory()->forPhone(CB_PHONE)->count(7)->create();

    cbText(__('telegram_bot.menu.order_status', [], 'en'));

    expect($user->fresh()->bot_dialog['buttons'])->toHaveCount(5);
});

it('says there is nothing to list for a customer without orders or repairs', function (string $kind) {
    cbUser();

    cbText(__("telegram_bot.menu.{$kind}_status", [], 'en'));

    expect(cbLast())->toBe(e(__("chatbot.lookup.{$kind}.none", [], 'en')));
})->with(['order', 'repair']);

it('still scopes a stored button by phone even if its id is tampered with', function () {
    $user = cbUser();
    $foreign = Order::factory()->forPhone('+998977654321')->create(['number' => 'O-2026-0002']);
    Order::factory()->forPhone(CB_PHONE)->create(['number' => 'O-2026-0001']);

    cbText(__('telegram_bot.menu.order_status', [], 'en'));
    $dialog = $user->fresh()->bot_dialog;
    $dialog['buttons'][0]['id'] = $foreign->id;
    $user->forceFill(['bot_dialog' => $dialog])->save();
    cbPress(cbButton($user, 'order'));

    expect(cbLast())->toBe(e(__('chatbot.lookup.order.not_found', [], 'en')))->not->toContain('O-2026-0002');
});
