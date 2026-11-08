<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\SendTelegramReply;
use App\Enums\ChatMode;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\SendBotAnswer;
use App\Jobs\SendBotPrompt;
use App\Models\Category;
use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Catalog\CategoryTree;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\Skills\FaqSkill;
use App\Support\CompanySettings;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Plan 28 C1: the Telegram adapter end to end through HandleTelegramUpdate
 * (switch, bot/manager mode, media, staff reply, TTL, callbacks).
 */
const CHAT_USER = 5560001;

function chatSetup(bool $on = true): void
{
    telegramBotConfigured();
    Http::fake(['api.telegram.org/*' => fn ($request) => app()->bound('chat.api')
        ? app('chat.api')($request)
        : Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 9999999)]])]);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::TELEGRAM_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => $on]);
    app(InstallChatbotDefaults::class)->handle();
    RuleIndex::bump();
}

/**
 * The shipped answer of a default rule, so the tests don't depend on the exact copy.
 */
function chatDefaultAnswer(string $key, string $locale = 'uz'): string
{
    return collect(require database_path('seeders/data/chatbot_defaults.php'))->firstWhere('key', $key)['answer'][$locale];
}

function chatUser(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge(['telegram_id' => CHAT_USER, 'locale' => 'uz', 'chat_mode' => ChatMode::Bot], $attributes));
}

function chatNextId(): int
{
    static $id = 70000;

    return ++$id;
}

function chatText(string $text, int $userId = CHAT_USER): string
{
    return app(HandleTelegramUpdate::class)->handle(telegramPrivateUpdate(chatNextId(), ['text' => $text], $userId));
}

function chatPress(string $data, int $userId = CHAT_USER): string
{
    return app(HandleTelegramUpdate::class)->handle([
        'update_id' => chatNextId(),
        'callback_query' => [
            'id' => 'cb'.chatNextId(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ali'],
            'message' => ['message_id' => 5, 'chat' => ['id' => $userId, 'type' => 'private']],
            'data' => $data,
        ],
    ]);
}

/**
 * @return list<array{method: string, data: array<string, mixed>}>
 */
function chatSent(): array
{
    return array_values(array_filter(telegramApiCalls(), fn (array $call) => $call['method'] === 'sendMessage'));
}

function chatInbound(): int
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)->count();
}

it('sends everything to the inbox while the switch is off, exactly as before', function () {
    chatSetup(on: false);
    $user = chatUser();
    Bus::fake([SendBotAnswer::class]);

    chatText('dostavka');

    expect(chatInbound())->toBe(1)
        ->and($user->fresh()->unread_count)->toBe(1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Bot);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

it('answers in bot mode, queues the answer and keeps the text out of the inbox', function () {
    chatSetup();
    $user = chatUser();

    chatText('Dostavka qancha turadi?');

    $sent = collect(chatSent())->last();

    expect(chatInbound())->toBe(0)
        ->and($user->fresh()->unread_count)->toBe(0)
        ->and($sent['data']['chat_id'])->toBe((string) CHAT_USER)
        ->and($sent['data']['parse_mode'])->toBe('HTML')
        ->and($sent['data']['text'])->toBe(e(chatDefaultAnswer('delivery')));
});

it('queues the answer after commit', function () {
    chatSetup();
    chatUser();
    Bus::fake([SendBotAnswer::class]);

    chatText('dostavka');

    Bus::assertDispatched(SendBotAnswer::class, fn (SendBotAnswer $job) => $job->text === chatDefaultAnswer('delivery'));
    expect(is_subclass_of(SendBotAnswer::class, ShouldQueueAfterCommit::class))->toBeTrue();
});

it('escapes markup in the outgoing text', function () {
    chatSetup();
    chatUser();
    ChatRule::query()->where('key', 'delivery')->update(['answer' => json_encode(['uz' => 'A <b>&</b> "q"'])]);
    RuleIndex::bump();

    chatText('dostavka');

    expect(collect(chatSent())->last()['data']['text'])->toBe('A &lt;b&gt;&amp;&lt;/b&gt; &quot;q&quot;');
});

it('claims an answer before sending so a second run sends nothing', function () {
    chatSetup();
    chatUser();
    Bus::fake([SendBotAnswer::class]);
    chatText('dostavka');

    $job = null;
    Bus::assertDispatched(SendBotAnswer::class, function (SendBotAnswer $dispatched) use (&$job) {
        $job = $dispatched;

        return true;
    });

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $client = app(TelegramClient::class);
    $job->handle($client);
    $job->handle($client);

    expect(chatSent())->toHaveCount(1);
});

it('records an unmatched phrase once and answers with the fallback and menu', function () {
    chatSetup();
    chatUser();

    chatText('qandaydir noaniq gap');
    chatText('qandaydir noaniq gap');

    $reply = collect(chatSent())->last();

    expect(ChatUnmatched::query()->sole()->count)->toBe(2)
        ->and($reply['data']['text'])->toBe(__('chatbot.fallback', [], 'uz'))
        ->and(json_encode($reply['data']['reply_markup']))->toContain('chat:handoff');
});

it('routes manager mode to the inbox', function () {
    chatSetup();
    chatUser(['chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHour()]);

    Bus::fake([SendBotAnswer::class]);

    chatText('dostavka');

    expect(chatInbound())->toBe(1);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

it('sends media to the inbox and switches to manager mode', function () {
    chatSetup();
    $user = chatUser();

    $update = telegramPrivateUpdate(chatNextId(), ['photo' => [['file_id' => 'f', 'file_unique_id' => 'u', 'width' => 9, 'height' => 9, 'file_size' => 10]]], CHAT_USER);
    unset($update['message']['text'], $user);
    app(HandleTelegramUpdate::class)->handle($update);

    expect(chatInbound())->toBe(1)
        ->and(TelegramUser::query()->sole()->chat_mode)->toBe(ChatMode::Manager);
});

it('switches to manager mode when staff reply', function () {
    chatSetup();
    $user = chatUser();
    $staff = staffUser(Role::Admin);

    app(SendTelegramReply::class)->handle($user, $staff, 'Salom, men menejerman');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and($user->fresh()->last_staff_reply_at)->not->toBeNull();

    chatText('dostavka');

    expect(chatInbound())->toBe(1);
});

it('hands off on the manager button and on a hand-off rule', function () {
    chatSetup();
    $user = chatUser();

    chatText('menejer kerak');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager);

    $user->forceFill(['chat_mode' => ChatMode::Bot])->save();
    chatPress('chat:handoff');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager);
});

it('returns to the bot after 8 hours but keeps a waiting customer with staff', function () {
    chatSetup();
    $user = chatUser(['chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHours(9)]);

    chatText('dostavka');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Bot)
        ->and(chatInbound())->toBe(0);

    $waiting = chatUser(['telegram_id' => CHAT_USER + 1, 'chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHours(10), 'last_inbound_at' => now()->subHours(9), 'unread_count' => 0]);

    chatText('dostavka', CHAT_USER + 1);

    expect($waiting->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(1);

    $unread = chatUser(['telegram_id' => CHAT_USER + 2, 'chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHours(30), 'unread_count' => 2]);

    chatText('dostavka', CHAT_USER + 2);

    expect($unread->fresh()->chat_mode)->toBe(ChatMode::Manager);
});

it('stays with staff before 8 hours', function () {
    chatSetup();
    $user = chatUser(['chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHours(7)]);

    chatText('dostavka');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(1);
});

it('goes back to the bot on /start when nobody is waiting', function () {
    chatSetup();
    $idle = chatUser(['chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subMinutes(5)]);

    chatText('/start');

    expect($idle->fresh()->chat_mode)->toBe(ChatMode::Bot);

    $waiting = chatUser(['telegram_id' => CHAT_USER + 1, 'chat_mode' => ChatMode::Manager, 'unread_count' => 1]);

    chatText('/start', CHAT_USER + 1);

    expect($waiting->fresh()->chat_mode)->toBe(ChatMode::Manager);
});

it('answers rule buttons from the menu and from the last answer only', function () {
    chatSetup();
    $user = chatUser();
    $delivery = ChatRule::query()->where('key', 'delivery')->value('id');
    $hidden = ChatRule::factory()->create(['key' => 'hidden_rule', 'in_menu' => false]);
    RuleIndex::bump();

    chatPress("chat:r:{$delivery}");
    expect(collect(chatSent())->last()['data']['text'])->toBe(e(chatDefaultAnswer('delivery')));

    $before = count(chatSent());
    chatPress("chat:r:{$hidden->id}");
    expect(chatSent())->toHaveCount($before);
    unset($user);
});

it('ignores bad callbacks', function (string $data) {
    chatSetup();
    chatUser();
    $inactive = ChatRule::factory()->inactive()->inMenu()->create(['key' => 'off_rule']);
    $webOnly = ChatRule::factory()->webOnly()->inMenu()->create(['key' => 'web_rule']);
    RuleIndex::bump();
    $data = str_replace(['{inactive}', '{web}'], [(string) $inactive->id, (string) $webOnly->id], $data);

    expect(chatPress($data))->toBe(HandleTelegramUpdate::PROCESSED)
        ->and(chatSent())->toBe([])
        ->and(chatInbound())->toBe(0);
})->with([
    'unknown rule' => ['chat:r:99999'],
    'inactive rule' => ['chat:r:{inactive}'],
    'web only rule' => ['chat:r:{web}'],
    'trailing newline' => ["chat:menu\n"],
    'no stored button' => ['chat:b:3:0'],
    'old format' => ['chat:b:0'],
    'wrong namespace' => ['chat:evil'],
    'huge id' => ['chat:r:12345678901'],
]);

it('ignores chat callbacks from unregistered users and while the switch is off', function () {
    chatSetup();
    TelegramUser::factory()->unregistered()->create(['telegram_id' => CHAT_USER + 5]);

    chatPress('chat:menu', CHAT_USER + 5);

    expect(chatSent())->toBe([]);

    Setting::query()->where('key', ChatbotSwitch::TELEGRAM_KEY)->firstOrFail()->update(['value' => false]);
    chatUser();
    chatPress('chat:menu');

    expect(chatSent())->toBe([]);
});

it('answers FAQ questions through server-stored buttons and keeps them working', function () {
    chatSetup();
    $user = chatUser();
    $first = FaqItem::factory()->create(['answer' => ['uz' => 'Birinchi javob matni.']]);
    $second = FaqItem::factory()->create(['answer' => ['uz' => 'Ikkinchi javob matni.']]);
    $hidden = FaqItem::factory()->inactive()->create();

    chatText('savol');

    $dialog = $user->fresh()->bot_dialog;
    $gen = $dialog['gen'];
    $markup = json_encode(collect(chatSent())->last()['data']['reply_markup']);

    expect($dialog['buttons'])->toBe([0 => ['t' => 'faq', 'id' => $first->id], 1 => ['t' => 'faq', 'id' => $second->id]])
        ->and($markup)->toContain("chat:b:{$gen}:0")->toContain("chat:b:{$gen}:1")
        ->and($markup)->not->toContain('"'.$hidden->id.'"');

    // Two questions in a row from the same list.
    chatPress("chat:b:{$gen}:0");
    expect(collect(chatSent())->last()['data']['text'])->toBe('Birinchi javob matni.');

    chatPress("chat:b:{$gen}:1");
    expect(collect(chatSent())->last()['data']['text'])->toBe('Ikkinchi javob matni.');

    // A new list makes the buttons of the old message stale.
    chatText('savol');
    $newGen = $user->fresh()->bot_dialog['gen'];
    $before = count(chatSent());

    expect($newGen)->not->toBe($gen);

    chatPress("chat:b:{$gen}:0");
    expect(chatSent())->toHaveCount($before);

    $first->update(['is_active' => false]);
    chatPress("chat:b:{$newGen}:0");

    expect(collect(chatSent())->last()['data']['text'])->toBe(__('chatbot.faq.unavailable', [], 'uz'));
});

it('does not serve FAQ items of a hidden category or product', function () {
    chatSetup();
    $category = Category::factory()->create(['is_active' => false]);
    $item = FaqItem::factory()->forCategory($category)->create();

    expect(app(FaqSkill::class)->item($item->id, 'uz'))->toBeNull();

    $category->update(['is_active' => true]);
    CategoryTree::flush();

    expect(app(FaqSkill::class)->item($item->id, 'uz'))->not->toBeNull();
});

it('shows company data only when it was entered in admin', function () {
    chatSetup();
    chatUser();

    chatText('manzil');
    expect(collect(chatSent())->last()['data']['text'])->toBe(__('chatbot.company.unavailable', [], 'uz'));

    Setting::query()->create(['key' => 'address', 'group' => 'company', 'value' => ['uz' => 'Toshkent, Amir Temur 1', 'ru' => 'Ташкент', 'en' => 'Tashkent']]);
    CompanySettings::flush();

    chatText('manzil');
    expect(collect(chatSent())->last()['data']['text'])->toContain('Amir Temur 1');
});

it('adds the FAQ and order status buttons to the menu only while the switch is on', function () {
    chatSetup(on: false);
    $labels = fn () => json_encode(BotKeyboards::markup('menu', 'en'));

    expect($labels())->not->toContain('Order status')
        ->and(BotKeyboards::menuButton('Order status'))->toBeNull();

    Setting::query()->where('key', ChatbotSwitch::TELEGRAM_KEY)->firstOrFail()->update(['value' => true]);

    expect($labels())->toContain('Order status')
        ->and(BotKeyboards::menuButton('Order status'))->toBe('order_status');
});

it('answers the order status button with ask a manager', function () {
    chatSetup();
    chatUser(['locale' => 'en']);

    chatText('Order status');

    expect(collect(chatSent())->last()['data']['text'])->toContain('manager')
        ->and(chatInbound())->toBe(0);
});

it('keeps a read but unanswered customer with staff after the migration, 9 hours later too', function () {
    chatSetup();
    // Read by staff (unread 0), the last inbound is 9 hours old and never answered.
    $user = chatUser(['unread_count' => 0, 'last_message_at' => now()->subHours(9), 'last_inbound_at' => now()->subHours(9)]);

    (require database_path('migrations/2026_11_08_100005_add_chat_mode_to_telegram_users_table.php'))->backfill();
    Bus::fake([SendBotAnswer::class]);

    chatText('dostavka');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and($user->fresh()->isWaitingForStaff())->toBeTrue()
        ->and(chatInbound())->toBe(1);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

it('sends text of a bot-mode customer with old unread messages to the inbox', function () {
    chatSetup();
    $user = chatUser(['chat_mode' => ChatMode::Bot, 'unread_count' => 3]);
    Bus::fake([SendBotAnswer::class]);

    chatText('dostavka');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(1);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

it('keeps a waiting customer waiting when they press contact a manager', function () {
    chatSetup();
    $user = chatUser(['chat_mode' => ChatMode::Manager, 'unread_count' => 2, 'last_inbound_at' => now()->subHours(3)]);

    chatText(__('telegram_bot.menu.contact_manager', [], 'uz'));

    $fresh = $user->fresh();

    expect($fresh->isWaitingForStaff())->toBeTrue()
        ->and($fresh->unread_count)->toBe(2)
        ->and($fresh->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(0);

    chatText('dostavka');

    expect(chatInbound())->toBe(1);
});

it('puts a system line in the inbox and alerts staff when a customer taps for a manager', function () {
    chatSetup();
    Event::fake([TelegramInboxMessage::class]);
    $user = chatUser();

    $user->forceFill(['locale' => 'ru'])->save();
    chatPress('chat:handoff');

    $line = TelegramMessage::query()->where('direction', TelegramDirection::In)->sole();

    expect($line->text)->toBe(__('telegram_bot.inbox.handoff_request', [], 'uz'))
        ->and($line->text)->not->toBe(__('telegram_bot.inbox.handoff_request', [], 'ru'))
        ->and($user->fresh()->unread_count)->toBe(1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and($user->fresh()->isWaitingForStaff())->toBeTrue();
    Event::assertDispatched(TelegramInboxMessage::class, 1);

    // A second tap while waiting adds nothing.
    chatPress('chat:handoff');

    expect(chatInbound())->toBe(1);
});

it('stores a typed hand-off request in the inbox as written and alerts staff', function () {
    chatSetup();
    Event::fake([TelegramInboxMessage::class]);
    $user = chatUser();

    chatText('menejer kerak, printer buzildi');

    expect(TelegramMessage::query()->where('direction', TelegramDirection::In)->sole()->text)->toBe('menejer kerak, printer buzildi')
        ->and($user->fresh()->unread_count)->toBe(1)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(TelegramMessage::query()->where('direction', TelegramDirection::Out)->count())->toBe(0);
    Event::assertDispatched(TelegramInboxMessage::class, 1);
});

it('keeps the auto-ack for ordinary inbox messages', function () {
    chatSetup();
    chatUser(['chat_mode' => ChatMode::Manager, 'last_staff_reply_at' => now()->subHour()]);

    chatText('printer buzildi');

    expect(TelegramMessage::query()->where('direction', TelegramDirection::Out)->count())->toBe(1);
});

it('hands off on the button even when the rules are unavailable', function () {
    chatSetup();
    $user = chatUser();
    app()->instance(RuleIndex::class, new class extends RuleIndex
    {
        public function load(): ?array
        {
            return null;
        }
    });

    chatPress('chat:handoff');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(1);
});

it('sends text to the inbox after a contact or location in bot mode', function () {
    chatSetup();
    $user = chatUser();

    $update = telegramPrivateUpdate(chatNextId(), ['location' => ['latitude' => 41.3, 'longitude' => 69.2]], CHAT_USER);
    unset($update['message']['text']);
    app(HandleTelegramUpdate::class)->handle($update);

    expect($user->fresh()->unread_count)->toBe(1);

    Bus::fake([SendBotAnswer::class]);
    chatText('dostavka');

    expect($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and(chatInbound())->toBe(2);
    Bus::assertNotDispatched(SendBotAnswer::class);
});

it('stops a text flood at the bot limits before the engine', function () {
    chatSetup();
    chatUser();
    Bus::fake([SendBotAnswer::class, SendBotPrompt::class]);

    foreach (range(1, 30) as $i) {
        chatText('dostavka');
    }

    Bus::assertDispatchedTimes(SendBotAnswer::class, BotLimits::PER_MINUTE);
    Bus::assertDispatchedTimes(SendBotPrompt::class, 1);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function chatAnswerJob(TelegramUser $user, ?string $callback = 'cb-1'): SendBotAnswer
{
    return (new SendBotAnswer($user->id, 'Javob', [], $callback))->withFakeQueueInteractions();
}

it('answers the callback even when the user is banned or the chat bot is off at send time', function (string $case) {
    chatSetup();
    $user = chatUser();

    if ($case === 'banned') {
        $user->forceFill(['banned_at' => now()])->save();
    } else {
        Setting::query()->where('key', ChatbotSwitch::TELEGRAM_KEY)->firstOrFail()->update(['value' => false]);
    }

    chatAnswerJob($user)->handle(app(TelegramClient::class));

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery']);
})->with(['banned', 'switched off']);

it('frees the claim on a retryable failure so the retry can send', function () {
    chatSetup();
    $user = chatUser();
    $state = new ArrayObject(['failing' => true]);
    app()->instance('chat.api', fn ($request) => str_ends_with($request->url(), '/sendMessage') && $state['failing']
        ? Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)
        : Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
    $job = chatAnswerJob($user, null);

    expect(fn () => $job->handle(app(TelegramClient::class)))->toThrow(TelegramException::class)
        ->and(Cache::has('chatbot-answer-claim:'.$job->claimToken))->toBeFalse();

    $state['failing'] = false;
    $job->handle(app(TelegramClient::class));

    expect(chatSent())->toHaveCount(2);
});

it('marks the user blocked on a 403 and does not retry', function () {
    chatSetup();
    $user = chatUser();
    app()->instance('chat.api', fn () => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403));

    chatAnswerJob($user, null)->handle(app(TelegramClient::class));

    expect($user->fresh()->blocked_at)->not->toBeNull()
        ->and(chatSent())->toHaveCount(1);
});

it('ignores a button of an older generation that would collide under a one-digit counter', function () {
    chatSetup();
    $item = FaqItem::factory()->create(['answer' => ['uz' => 'Javob matni.']]);
    $user = chatUser(['bot_dialog' => ['rules' => [], 'buttons' => [0 => ['t' => 'faq', 'id' => $item->id]], 'gen' => 3, 'at' => now()->unix()]]);

    chatPress('chat:b:13:0');
    chatPress('chat:b:93:0');

    expect(chatSent())->toBe([]);

    chatPress('chat:b:3:0');

    expect(collect(chatSent())->last()['data']['text'])->toBe('Javob matni.');

    // The counter now runs to 99 and wraps to 0.
    $user->forceFill(['bot_dialog' => ['rules' => [], 'buttons' => [], 'gen' => 99, 'at' => now()->unix()]])->save();
    chatText('savol');

    expect($user->fresh()->bot_dialog['gen'])->toBe(0);
});
