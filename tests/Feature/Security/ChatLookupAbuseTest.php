<?php

require_once __DIR__.'/ChatAbuseKit.php';

use App\Actions\Chat\TestChatPhrase;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\RepairDeviceType;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Jobs\SendChatLookupFailureAlert;
use App\Models\ChatUnmatched;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C3 / C3b security abuse cases 1-11 and 16 (section 17): the order
 * and repair status lookups of the Telegram bot, the failure alert and the
 * website / console paths that must never read the tables.
 */
beforeEach(fn () => labSetup());

function labFailureKey(string $kind, string $number): string
{
    return 'chat-lookup-fail:'.hash('sha256', $kind.'|'.$number);
}

// Abuse case 1
it('answers another customers real order number with not found and counts one failure for that number', function () {
    labUser();
    labOrder(['number' => 'O-2026-0042', 'phone' => '+998909998877']);

    $reply = labTalk('O-2026-0042');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.order.not_found'))
        ->and(RateLimiter::attempts(labFailureKey('order', 'O-2026-0042')))->toBe(1);

    foreach (labPrivateMarkers() as $marker) {
        expect(labText($reply))->not->toContain($marker);
    }
});

// Abuse case 2
it('gives the same reply and one orders query for every kind of miss', function (string $scenario) {
    labUser();

    match ($scenario) {
        'other phone' => labOrder(['phone' => '+998909998877']),
        'test order' => labOrder(['is_test' => true]),
        '13 months old' => labOrder(['created_at' => now()->subMonths(13)]),
        'unknown number' => null,
    };

    [$reply, $counts] = labQueries(fn () => labTalk('O-2026-0001'), 'orders');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.order.not_found'))
        ->and($counts['orders'])->toBe(1);
})->with(['other phone', 'test order', '13 months old', 'unknown number']);

// Abuse case 2
it('runs the same single orders query for the owners real order', function () {
    labUser();
    labOrder();

    [$reply, $counts] = labQueries(fn () => labTalk('O-2026-0001'), 'orders');

    expect(labText($reply))->toContain(__('chatbot.lookup.order.title', ['number' => 'O-2026-0001'], 'en'))
        ->and($counts['orders'])->toBe(1);

    foreach (labPrivateMarkers() as $marker) {
        expect(labText($reply))->not->toContain($marker);
    }
});

// Abuse case 3
it('offers a manager and reads no order or repair for an anonymous +888 phone', function (string $number) {
    labUser(['phone' => '+88800012345']);
    labOrder(['phone' => '+88800012345']);
    labRepair(['contact_phone' => '+88800012345']);

    [$reply, $counts] = labQueries(fn () => labTalk($number), 'orders', 'repairs');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.unavailable'))
        ->and($counts)->toBe(['orders' => 0, 'repairs' => 0]);
})->with(['order number' => 'O-2026-0001', 'repair number' => 'R-2026-000123']);

// Abuse case 3
it('ignores a banned customer and reads no order or repair', function (string $number) {
    labUser(['banned_at' => now()]);
    labOrder();
    labRepair();

    [$reply, $counts] = labQueries(fn () => labTalk($number), 'orders', 'repairs');

    expect($reply)->toBeNull()
        ->and($counts)->toBe(['orders' => 0, 'repairs' => 0]);
})->with(['order number' => 'O-2026-0001', 'repair number' => 'R-2026-000123']);

// Abuse case 3
it('reads no order or repair for a customer who has not registered', function (string $number) {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => LAB_USER, 'locale' => 'en']);
    labOrder();
    labRepair();

    [$reply, $counts] = labQueries(fn () => labTalk($number), 'orders', 'repairs');

    expect(labText($reply))->not->toContain('O-2026-0001')->not->toContain('R-2026-000123')
        ->and($counts)->toBe(['orders' => 0, 'repairs' => 0]);
})->with(['order number' => 'O-2026-0001', 'repair number' => 'R-2026-000123']);

// Abuse case 4
it('lets only the newest registration of a recycled phone look up', function () {
    labUser(['telegram_id' => LAB_USER, 'registered_at' => now()->subYear()]);
    labUser(['telegram_id' => LAB_USER + 1, 'registered_at' => now()->subDay()]);
    labOrder();

    [$older, $olderCounts] = labQueries(fn () => labTalk('O-2026-0001', LAB_USER), 'orders');
    [$newer, $newerCounts] = labQueries(fn () => labTalk('O-2026-0001', LAB_USER + 1), 'orders');

    expect(labText($older))->toBe(labLang('chatbot.lookup.unavailable'))
        ->and($olderCounts['orders'])->toBe(0)
        ->and(labText($newer))->toContain(__('chatbot.lookup.order.title', ['number' => 'O-2026-0001'], 'en'))
        ->and($newerCounts['orders'])->toBe(1);
});

// Abuse case 5
it('never looks up a test order number: T- goes to no orders query', function () {
    labUser();
    labOrder(['number' => 'T-2026-0001', 'is_test' => true]);

    [$reply, $counts] = labQueries(fn () => labTalk('T-2026-0001'), 'orders');

    expect($counts['orders'])->toBe(0)
        ->and(labText($reply))->not->toContain(__('chatbot.lookup.order.title', ['number' => 'T-2026-0001'], 'en'))
        ->and(RateLimiter::attempts(labFailureKey('order', 'T-2026-0001')))->toBe(0);
});

// Abuse case 6
it('routes a typed number to the lookup, never to product search or the unmatched list', function (string $text, string $table, string $title) {
    labUser();
    labOrder();
    labRepair();

    [$reply, $counts] = labQueries(fn () => labTalk($text), 'orders', 'repairs', 'products');

    expect($counts[$table])->toBe(1)
        ->and($counts['products'])->toBe(0)
        ->and(labText($reply))->toContain($title)
        ->and(ChatUnmatched::query()->count())->toBe(0);
})->with([
    'spaced lower case' => ['o 2026 0001', 'orders', 'Order O-2026-0001'],
    'hash and prefix' => ['#R-2026-000123', 'repairs', 'Repair R-2026-000123'],
    'Cyrillic O' => ['О-2026-0001', 'orders', 'Order O-2026-0001'],
    'Cyrillic R' => ['Р-2026-000123', 'repairs', 'Repair R-2026-000123'],
]);

// Abuse case 6
it('does not treat a number with text around it as a lookup', function (string $text) {
    labUser();
    labOrder();

    $reply = labTalk($text);

    expect(labText($reply))->not->toContain(__('chatbot.lookup.order.title', ['number' => 'O-2026-0001'], 'en'))
        ->and(RateLimiter::attempts(labFailureKey('order', 'O-2026-0001')))->toBe(0);
})->with(['text before' => 'my order O-2026-0001', 'trailing text' => 'O-2026-0001 please', 'trailing newline' => "O-2026-0001\nx", 'too many digits' => 'O-2026-0001234567']);

// Abuse case 7
it('limits a customer to five lookups an hour and makes no query for the sixth', function () {
    labUser();
    labOrder();

    foreach (range(1, 5) as $attempt) {
        expect(labText(labTalk('O-2026-0001')))->toContain('O-2026-0001');
    }

    [$reply, $counts] = labQueries(fn () => labTalk('O-2026-0001'), 'orders');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.limited'))
        ->and($counts['orders'])->toBe(0);
});

// Abuse case 7
it('limits all customers together to 300 lookups an hour', function () {
    labUser();
    labOrder();

    foreach (range(1, 300) as $hit) {
        RateLimiter::hit('chat-lookup:global', 3600);
    }

    [$reply, $counts] = labQueries(fn () => labTalk('O-2026-0001'), 'orders');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.limited'))
        ->and($counts['orders'])->toBe(0);
});

// Abuse case 8
it('queues one counts-only alert for five failures on one number from five accounts and the owner still gets the order', function () {
    Bus::fake([SendChatLookupFailureAlert::class]);
    labUser();
    labOrder(['number' => 'O-2026-0099']);

    foreach (range(1, 5) as $i) {
        labUser(['telegram_id' => LAB_USER + 10 + $i, 'phone' => '+99890555000'.$i]);
    }

    foreach (range(1, 4) as $i) {
        labTalk('O-2026-0099', LAB_USER + 10 + $i);
    }

    Bus::assertNotDispatched(SendChatLookupFailureAlert::class);

    labTalk('O-2026-0099', LAB_USER + 15);

    Bus::assertDispatchedTimes(SendChatLookupFailureAlert::class, 1);
    Bus::assertDispatched(SendChatLookupFailureAlert::class, fn (SendChatLookupFailureAlert $job): bool => $job->failures === 5
        && ! str_contains(serialize($job), '0099')
        && ! str_contains(serialize($job), '555000'));

    // A sixth failure on the same number, and five on another number in the same hour: still one alert.
    labTalk('O-2026-0099', LAB_USER + 11);

    foreach (range(1, 5) as $i) {
        labTalk('O-2026-0098', LAB_USER + 10 + $i);
    }

    Bus::assertDispatchedTimes(SendChatLookupFailureAlert::class, 1);

    expect(labText(labTalk('O-2026-0099')))->toContain(__('chatbot.lookup.order.title', ['number' => 'O-2026-0099'], 'en'))
        ->and(collect(labSentCalls())->pluck('data.chat_id')->map(fn ($id) => (string) $id)->all())->not->toContain(TG_MANAGEMENT_CHAT);
});

it('keeps the per-number alert marker when the global hourly alert is refused', function () {
    Bus::fake([SendChatLookupFailureAlert::class]);
    labUser();
    labOrder(['number' => 'O-2026-0099']);

    foreach (range(1, 15) as $i) {
        labUser(['telegram_id' => LAB_USER + 10 + $i, 'phone' => '+9989055'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
    }

    foreach (range(1, 5) as $i) {
        labTalk('O-2026-0099', LAB_USER + 10 + $i);
    }

    foreach (range(1, 5) as $i) {
        labTalk('O-2026-0098', LAB_USER + 10 + $i);
    }

    Bus::assertDispatchedTimes(SendChatLookupFailureAlert::class, 1);

    $this->travel(61)->minutes();

    labTalk('O-2026-0098', LAB_USER + 11);

    Bus::assertDispatchedTimes(SendChatLookupFailureAlert::class, 2);

    $this->travelBack();
});

it('sends the alert job text with the failure count and no number or phone', function () {
    (new SendChatLookupFailureAlert(5))->handle();

    $alert = collect(labSentCalls())->firstWhere('data.chat_id', TG_MANAGEMENT_CHAT);

    expect($alert)->not->toBeNull()
        ->and($alert['data']['text'])->toContain('5')->not->toContain('O-2026')->not->toContain('+998');
});

// Abuse case 9
it('gives the same reply and one repairs query for every kind of repair miss', function (string $scenario) {
    labUser();

    match ($scenario) {
        'other phone' => labRepair(['contact_phone' => '+998909998877']),
        'closed 91 days' => labRepair(['status' => RepairStatus::Delivered, 'closed_at' => now()->subDays(91)]),
        'unknown number' => null,
    };

    [$reply, $counts] = labQueries(fn () => labTalk('R-2026-000123'), 'repairs');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.repair.not_found'))
        ->and($counts['repairs'])->toBe(1);
})->with(['other phone', 'closed 91 days', 'unknown number']);

// Abuse case 9
it('finds an open repair and one closed 89 days ago without showing anything private', function (RepairStatus $status, ?int $closedDaysAgo) {
    labUser();
    $engineer = User::factory()->create(['name' => 'MarkEngineer Person']);
    labRepair([
        'status' => $status,
        'closed_at' => $closedDaysAgo === null ? null : now()->subDays($closedDaysAgo),
        'assigned_to_id' => $engineer->id,
    ]);

    [$reply, $counts] = labQueries(fn () => labTalk('R-2026-000123'), 'repairs');

    expect($counts['repairs'])->toBe(1)
        ->and(labText($reply))->toContain(__('chatbot.lookup.repair.title', ['number' => 'R-2026-000123'], 'en'))
        ->and(labText($reply))->toContain('Zebra DS2208')
        ->and(labText($reply))->toContain(__('enums.repair_status.'.$status->value, [], 'en'))
        ->and(labText($reply))->not->toContain('MarkEngineer');

    foreach (labPrivateMarkers() as $marker) {
        expect(labText($reply))->not->toContain($marker);
    }
})->with([
    'open' => [RepairStatus::InRepair, null],
    'closed 89 days' => [RepairStatus::Delivered, 89],
]);

// Abuse case 9
it('shows a fixed line and no amount for awaiting approval and ready repairs', function (RepairStatus $status, string $line) {
    labUser();
    labRepair(['status' => $status]);

    $text = labText(labTalk('R-2026-000123'));

    expect($text)->toContain(labLang($line));

    foreach (labPrivateMarkers() as $marker) {
        expect($text)->not->toContain($marker);
    }
})->with([
    'awaiting approval' => [RepairStatus::AwaitingApproval, 'chatbot.lookup.repair.awaiting_approval'],
    'ready' => [RepairStatus::Ready, 'chatbot.lookup.repair.ready'],
]);

// Abuse case 10
it('cleans and escapes the device line of a repair', function () {
    labUser();
    labRepair([
        'device_type' => RepairDeviceType::Scanner,
        'device_brand' => "Ze\u{202E}br\u{200B}a\u{2066}",
        'device_model' => "DS\u{0007}<b>2208</b>",
    ]);

    $text = labText(labTalk('R-2026-000123'));
    preg_match('/^Device: (.*)$/m', $text, $match);

    expect($match[1] ?? '')->toContain('Zebra')->toContain('&lt;b&gt;2208&lt;/b&gt;')
        ->not->toContain('<b>')
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $match[1] ?? ''))->toBe(0);
});

// Abuse case 10
it('cuts a very long device line to 60 characters', function () {
    labUser();
    labRepair(['device_brand' => str_repeat('B', 120), 'device_model' => str_repeat('M', 120)]);

    preg_match('/^Device: (.*)$/m', labText(labTalk('R-2026-000123')), $match);

    expect(mb_strlen(html_entity_decode($match[1] ?? '')))->toBeLessThanOrEqual(60);
});

// Abuse case 11
it('answers the website channel with Telegram only and reads no orders or repairs', function (string $text) {
    labOrder();
    labRepair();

    [$reply, $counts] = labQueries(
        fn () => app(Engine::class)->respond(new ChatContext(ChatChannel::Web, 'en', $text)),
        'orders', 'repairs',
    );

    expect($counts)->toBe(['orders' => 0, 'repairs' => 0])
        ->and($reply->outcome)->not->toBeIn([ChatReply::ORDER_STATUS, ChatReply::REPAIR_STATUS])
        ->and($reply->text)->not->toContain('Privatniy')->not->toContain('MARK');
})->with(['order number' => 'O-2026-0001', 'repair number' => 'R-2026-000123', 'order status rule' => 'order status', 'repair status rule' => 'repair status']);

// Abuse case 11
it('tells the website visitor that a typed number is looked up in Telegram only', function () {
    $reply = app(Engine::class)->respond(new ChatContext(ChatChannel::Web, 'en', 'O-2026-0001'));

    expect($reply->text)->toBe(__('chatbot.lookup.web.order', [], 'en'))
        ->and(collect($reply->buttons)->pluck('type')->all())->toBe(['handoff']);
});

// Abuse case 11
it('reports the lookup in the console dry run with zero queries on private tables and no jobs', function (string $phrase, ChatAction $action) {
    Queue::fake();
    $admin = staffUser(Role::Admin);
    labOrder();
    labRepair();

    [$result, $counts] = labQueries(
        fn () => app(TestChatPhrase::class)->handle($admin, $phrase, 'en', ChatChannel::Telegram),
        'orders', 'repairs',
    );

    expect($counts)->toBe(['orders' => 0, 'repairs' => 0])
        ->and($result['would_run'])->toBe($action->label())
        ->and($result['reply'])->toBeNull();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'order number' => ['O-2026-0001', ChatAction::OrderStatus],
    'repair number' => ['#R-2026-000123', ChatAction::RepairStatus],
]);

// Abuse case 16
it('stops the spinner only for an old generation, an empty slot and a trailing newline', function (string $case) {
    labUser();
    labOrder();
    labTalk(__('telegram_bot.menu.order_status', [], 'en'));
    $dialog = TelegramUser::query()->where('telegram_id', LAB_USER)->firstOrFail()->bot_dialog;
    $gen = $dialog['gen'];

    $data = match ($case) {
        'old generation' => 'chat:b:'.(($gen + 98) % 100).':0',
        'empty slot' => 'chat:b:'.$gen.':9',
        'trailing newline' => 'chat:b:'.$gen.":0\n",
    };

    $callsBefore = count(telegramApiCalls());
    $reply = labPress($data);

    expect($reply)->toBeNull()
        ->and(collect(array_slice(telegramApiCalls(), $callsBefore))->pluck('method')->all())->toBe(['answerCallbackQuery']);
})->with(['old generation', 'empty slot', 'trailing newline']);

// Abuse case 16
it('answers a button that points at another customers order with not found and nothing of theirs', function () {
    $user = labUser();
    labOrder();
    $foreign = labOrder(['number' => 'O-2026-0042', 'phone' => '+998909998877']);
    labTalk(__('telegram_bot.menu.order_status', [], 'en'));

    $dialog = $user->fresh()->bot_dialog;
    $dialog['buttons'][0] = ['t' => 'order', 'id' => $foreign->id];
    $user->forceFill(['bot_dialog' => $dialog])->save();

    $reply = labPress('chat:b:'.$dialog['gen'].':0');

    expect(labText($reply))->toBe(labLang('chatbot.lookup.order.not_found'));

    foreach (labPrivateMarkers() as $marker) {
        expect(labText($reply))->not->toContain($marker);
    }
});
