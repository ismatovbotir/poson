<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Telegram\SendTelegramReply;
use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\SendBotAnswer;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\RuleIndex;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Plan 28 C1, CB-4 / section 15: abuse cases of the rule-based chat bot and its
 * Telegram adapter. Updates go through the real webhook route.
 *
 * Case 2 (old button after the generation wraps) is partly covered by
 * ChatTelegramFlowTest "ignores a button of an older generation that would
 * collide under a one-digit counter" (collisions, and that the counter wraps
 * to 0), but not the old button staying dead across the wrap: tested here.
 *
 * Case 6 (passwords and tokens without "=") is fully covered by UnmatchedTest
 * "masks personal data before storing sample and normalized", dataset rows
 * "parol colon", "password space", "parol cyrillic dash" and "token colon".
 */
const ABUSE_USER = 5570001;

function chatAbuseSetup(bool $on = true): void
{
    telegramBotConfigured();
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    chatAbuseSwitch($on);
    app(InstallChatbotDefaults::class)->handle();
    RuleIndex::bump();
}

/**
 * The real settings save, as the admin screen does it.
 */
function chatAbuseSwitch(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::TELEGRAM_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => $on]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function chatAbuseUser(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge(['telegram_id' => ABUSE_USER, 'locale' => 'uz', 'chat_mode' => ChatMode::Bot], $attributes));
}

function chatAbuseId(): int
{
    static $id = 90000;

    return ++$id;
}

function chatAbuseText(string $text): void
{
    postTelegramUpdate(telegramPrivateUpdate(chatAbuseId(), ['text' => $text], ABUSE_USER))->assertOk();
}

function chatAbusePress(string $data): void
{
    postTelegramUpdate([
        'update_id' => chatAbuseId(),
        'callback_query' => [
            'id' => 'cb'.chatAbuseId(),
            'from' => ['id' => ABUSE_USER, 'is_bot' => false, 'first_name' => 'Ali'],
            'message' => ['message_id' => 5, 'chat' => ['id' => ABUSE_USER, 'type' => 'private']],
            'data' => $data,
        ],
    ])->assertOk();
}

/**
 * @return list<string>
 */
function chatAbuseSentTexts(): array
{
    return collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text')->values()->all();
}

function chatAbuseInbound(): int
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)->count();
}

it('stops the second queued answer when the chat bot is switched off between two jobs of one worker', function () {
    chatAbuseSetup();
    $user = chatAbuseUser();
    $first = (new SendBotAnswer($user->id, 'Birinchi javob', [], 'cb-1'))->withFakeQueueInteractions();
    $second = (new SendBotAnswer($user->id, 'Ikkinchi javob', [], 'cb-2'))->withFakeQueueInteractions();
    $client = app(TelegramClient::class);

    // Job one runs and memoizes the switch (on) for the rest of this job's scope.
    $first->handle($client);
    expect(ChatbotSwitch::isEnabled())->toBeTrue();

    // The owner turns the bot off through the real save; the same process keeps working.
    chatAbuseSwitch(false);

    $second->handle($client);

    $calls = collect(telegramApiCalls());

    expect(chatAbuseSentTexts())->toBe(['Birinchi javob'])
        ->and($calls->where('method', 'answerCallbackQuery')->pluck('data.callback_query_id')->all())->toBe(['cb-1', 'cb-2'])
        ->and(ChatbotSwitch::isEnabled())->toBeFalse();
});

it('keeps old buttons dead across the generation wrap from 99 to 0', function () {
    chatAbuseSetup();
    $item = FaqItem::factory()->create(['answer' => ['uz' => 'Javob matni.']]);
    $user = chatAbuseUser(['bot_dialog' => ['rules' => [], 'buttons' => [0 => ['t' => 'faq', 'id' => $item->id]], 'gen' => 98, 'at' => now()->unix()]]);

    chatAbuseText('savol');
    expect($user->fresh()->bot_dialog['gen'])->toBe(99);

    chatAbuseText('savol');
    expect($user->fresh()->bot_dialog['gen'])->toBe(0);

    chatAbuseText('savol');
    expect($user->fresh()->bot_dialog['gen'])->toBe(1);

    $before = count(chatAbuseSentTexts());

    foreach (['98', '99', '0', '2'] as $oldGeneration) {
        chatAbusePress("chat:b:{$oldGeneration}:0");
    }

    expect(chatAbuseSentTexts())->toHaveCount($before);

    chatAbusePress('chat:b:1:0');

    expect(collect(chatAbuseSentTexts())->last())->toBe('Javob matni.');
});

dataset('waiting customer', [
    'unread' => [fn () => ['chat_mode' => ChatMode::Manager, 'unread_count' => 2, 'last_inbound_at' => now()->subHours(20), 'last_staff_reply_at' => now()->subHours(30)]],
    'open, last inbound unanswered, read' => [fn () => ['chat_mode' => ChatMode::Manager, 'unread_count' => 0, 'last_inbound_at' => now()->subHours(20), 'last_staff_reply_at' => now()->subHours(30)]],
    'open, never answered, stored mode bot' => [fn () => ['chat_mode' => ChatMode::Bot, 'unread_count' => 0, 'last_inbound_at' => now()->subHours(20)]],
]);

it('keeps manager mode for a waiting customer on menu and /start, and the next text goes to the inbox', function (Closure $attributes, string $action) {
    chatAbuseSetup();
    $attributes = $attributes();
    $user = chatAbuseUser($attributes);
    Bus::fake([SendBotAnswer::class]);

    if ($action === 'menu') {
        chatAbusePress('chat:menu');
    } else {
        chatAbuseText('/start');
    }

    expect($user->fresh()->isWaitingForStaff())->toBeTrue()
        ->and($user->fresh()->chat_mode)->toBe($attributes['chat_mode']);

    $inboxBefore = chatAbuseInbound();

    chatAbuseText('dostavka');

    expect(chatAbuseInbound())->toBe($inboxBefore + 1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager);
    Bus::assertNotDispatched(function (SendBotAnswer $job) {
        return $job->text === collect(require database_path('seeders/data/chatbot_defaults.php'))->firstWhere('key', 'delivery')['answer']['uz'];
    });
})->with('waiting customer')->with(['menu', 'start']);

it('reopens a closed conversation on a hand-off tap with one unread, one alert and an Uzbek system line', function () {
    chatAbuseSetup();
    Event::fake([TelegramInboxMessage::class]);
    $user = TelegramUser::factory()->registered()->closed()->create([
        'telegram_id' => ABUSE_USER,
        'locale' => 'ru',
        'chat_mode' => ChatMode::Bot,
        'unread_count' => 0,
        'last_inbound_at' => now()->subDays(3),
        'last_staff_reply_at' => now()->subDays(2),
    ]);

    chatAbusePress('chat:handoff');

    $line = TelegramMessage::query()->where('direction', TelegramDirection::In)->sole();
    $fresh = $user->fresh();

    expect($fresh->conversation_status)->toBe(ConversationStatus::Open)
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->unread_count)->toBe(1)
        ->and($fresh->chat_mode)->toBe(ChatMode::Manager)
        ->and($line->text)->toBe(__('telegram_bot.inbox.handoff_request', [], 'uz'))
        ->and($line->text)->not->toBe(__('telegram_bot.inbox.handoff_request', [], 'ru'));
    Event::assertDispatchedTimes(TelegramInboxMessage::class, 1);
});

it('stores one system line and sends one staff alert for five hand-off taps in a row', function () {
    chatAbuseSetup();
    Event::fake([TelegramInboxMessage::class]);
    $user = chatAbuseUser();

    foreach (range(1, 5) as $tap) {
        chatAbusePress('chat:handoff');
    }

    expect(chatAbuseInbound())->toBe(1)
        ->and($user->fresh()->unread_count)->toBe(1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager);
    Event::assertDispatchedTimes(TelegramInboxMessage::class, 1);
});

it('sends the next text to the inbox when the switch is turned on while a customer waits', function (bool $staffRead) {
    chatAbuseSetup(on: false);
    $user = chatAbuseUser();
    $staff = staffUser(Role::Admin);

    // Staff answer while the bot is off, then the customer writes again and is not answered.
    app(SendTelegramReply::class)->handle($user, $staff, 'Salom, men menejerman');
    $this->travel(2)->hours();
    chatAbuseText('yana savolim bor');

    if ($staffRead) {
        $user->refresh()->forceFill(['unread_count' => 0])->save();
    }

    expect($user->fresh()->isWaitingForStaff())->toBeTrue();

    chatAbuseSwitch(true);
    RuleIndex::bump();
    Bus::fake([SendBotAnswer::class]);
    $inboxBefore = chatAbuseInbound();

    chatAbuseText('dostavka');

    expect(chatAbuseInbound())->toBe($inboxBefore + 1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager);
    Bus::assertNotDispatched(SendBotAnswer::class);
})->with([
    'unread' => [false],
    'read but unanswered' => [true],
]);
