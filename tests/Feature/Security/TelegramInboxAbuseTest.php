<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\RetryForward;
use App\Actions\Telegram\StoreInboundMessage;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendTelegramMessage;
use App\Listeners\SendTelegramInboxAlert;
use App\Livewire\Admin\Telegram\Settings as TelegramSettings;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Plan 14 v2 B4 abuse cases 1-29 (security-reviewer) for the inbox intake,
 * the alert, the media forward and the staff retry. Happy paths live in
 * tests/Feature/Telegram/TelegramInboxTest.php; here only the hostile angles.
 * Bot API faked (fake token), queue sync, test DB only.
 */
const IAB_USER = 5570001;

const IAB_OTHER = 5570002;

const IAB_SALES_CHAT = '-1001111111111';

const IAB_ACK_MARKER = 'telegram-inbox-ack:';

const IAB_SECRET = 'SECRET-customer-text-+998901234567';

function iabBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

/**
 * Fake Bot API by method (an array is the ok body, an int an error status, a
 * closure returns either); calling it again only swaps the answers.
 *
 * @param  array<string, mixed>  $responses
 */
function iabApi(array $responses = []): void
{
    $faked = app()->bound('iab.api');
    app()->instance('iab.api', $responses);

    if ($faked) {
        return;
    }

    Http::fake(['api.telegram.org/*' => function (Request $request) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $response = app('iab.api')[$method] ?? match ($method) {
            'getChat' => ['id' => (int) ($request->data()['chat_id'] ?? 0), 'type' => 'supergroup', 'title' => 'Team'],
            'sendMessage' => ['message_id' => iabNextSentId((string) ($request->data()['chat_id'] ?? ''))],
            'copyMessage' => ['message_id' => 901],
            default => true,
        };

        if ($response instanceof Closure) {
            $response = $response($request);
        }

        if (is_int($response)) {
            return Http::response(['ok' => false, 'error_code' => $response, 'description' => 'Bad Request: secret description', 'parameters' => ['retry_after' => 7]], $response);
        }

        return Http::response(['ok' => true, 'result' => $response]);
    }]);
}

/**
 * @return list<array{method: string, data: array<string, mixed>}>
 */
function iabCalls(?string $method = null, ?string $chatId = null): array
{
    return collect(telegramApiCalls())
        ->filter(fn (array $call) => $method === null || $call['method'] === $method)
        ->filter(fn (array $call) => $chatId === null || (string) ($call['data']['chat_id'] ?? '') === $chatId)
        ->values()->all();
}

function iabNextSentId(string $chatId): int
{
    $ids = app()->bound('iab.sent') ? app('iab.sent') : [];
    $ids[$chatId] = ($ids[$chatId] ?? 899) + 1;
    app()->instance('iab.sent', $ids);

    return $ids[$chatId];
}

function iabNextUpdateId(): int
{
    static $id = 800_000;

    return ++$id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function iabUser(array $attributes = [], int $telegramId = IAB_USER): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge([
        'telegram_id' => $telegramId,
        'name' => 'Ali Valiyev',
        'username' => 'ali_secret_username',
        'declared_company' => 'OOO Secret Company',
        'declared_inn' => '123456789',
        'locale' => 'en',
    ], $attributes));
}

/**
 * A private message update (no "text" unless given).
 *
 * @param  array<string, mixed>  $message
 * @return array<string, mixed>
 */
function iabUpdate(array $message, int $userId = IAB_USER): array
{
    $update = telegramPrivateUpdate(iabNextUpdateId(), $message, $userId);

    if (! array_key_exists('text', $message)) {
        unset($update['message']['text']);
    }

    return $update;
}

/**
 * @param  array<string, mixed>  $message
 */
function iabSend(array $message, int $userId = IAB_USER): string
{
    return app(HandleTelegramUpdate::class)->handle(iabUpdate($message, $userId));
}

/**
 * @param  array<string, mixed>  $message
 */
function iabPost(array $message, int $userId = IAB_USER): TestResponse
{
    return postTelegramUpdate(iabUpdate($message, $userId));
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function iabPhoto(array $extra = []): array
{
    return array_merge(['photo' => [
        ['file_id' => 'small-file-id', 'file_unique_id' => 'small-unique', 'width' => 90, 'height' => 60, 'file_size' => 1200],
        ['file_id' => 'big-file-id', 'file_unique_id' => 'big-unique', 'width' => 1280, 'height' => 960, 'file_size' => 90000],
    ]], $extra);
}

/**
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function iabDocument(array $document = [], array $extra = []): array
{
    return array_merge(['document' => array_merge([
        'file_id' => 'doc-file-id', 'file_unique_id' => 'doc-unique', 'file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf', 'file_size' => 52000,
    ], $document)], $extra);
}

function iabLastInbound(): TelegramMessage
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)->latest('id')->firstOrFail();
}

function iabInboundCount(?TelegramMessageKind $kind = null): int
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)
        ->when($kind !== null, fn ($query) => $query->where('kind', $kind))->count();
}

/**
 * An inbound, pending media row of a conversation (what the intake would store).
 */
function iabMediaRow(TelegramUser $user, int $messageId = 50): TelegramMessage
{
    return TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => $messageId,
    ]);
}

/**
 * Run the forward job like a worker would (middleware included); a Telegram
 * error is swallowed (the worker retries).
 */
function iabRunForward(TelegramMessage $row): void
{
    $job = (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->withFakeQueueInteractions();

    try {
        $job->middleware()[0]->handle($job, fn (ForwardTelegramMedia $job) => $job->handle(app(TelegramClient::class)));
    } catch (Throwable) {
        // retried by the worker
    }
}

/**
 * @return Closure(): list<string> JSON-encoded log records so far, with exception messages
 */
function iabCaptureLogs(): Closure
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $exception = $event->context['exception'] ?? null;
        $logged[] = json_encode([$event->message, array_filter($event->context, fn ($value) => ! $value instanceof Throwable)])
            .($exception instanceof Throwable ? $exception->getMessage().get_class($exception) : '');
    });

    return function () use (&$logged): array {
        return $logged;
    };
}

/**
 * Team-chat alert texts (the "new message" alert) sent to the sales chat.
 *
 * @return list<string>
 */
function iabAlertTexts(): array
{
    return collect(iabCalls('sendMessage', IAB_SALES_CHAT))
        ->pluck('data.text')
        ->filter(fn (string $text) => str_contains($text, e(__('telegram.inbox.alert_title', [], 'uz'))))
        ->values()->all();
}

/**
 * Make the second telegram_messages insert of the next intake fail with a DB
 * exception that carries customer text (the first row, the unread counter and
 * the ack marker are already written by then).
 */
function iabFailSecondInsert(): void
{
    $inserts = 0;
    TelegramMessage::creating(function () use (&$inserts): void {
        if (++$inserts === 2) {
            throw new QueryException('sqlite', 'insert into "telegram_messages" ("text") values (?)', [IAB_SECRET], new PDOException('SQLSTATE[HY000]: '.IAB_SECRET));
        }
    });
}

beforeEach(function () {
    telegramBotConfigured();
    iabBotOn();
    iabApi();
});

/* ------------------------------------------------------------- 1-2 dedupe */

// 1. A Telegram message id is unique per chat, not globally.
it('case 1: stores the same message id once per user and adds nothing for a repeat under a new update id', function () {
    Bus::fake([ForwardTelegramMedia::class, DeliverBotMessage::class]);
    Event::fake([TelegramInboxMessage::class]);
    $users = [iabUser(), iabUser([], IAB_OTHER)];

    foreach ($users as $user) {
        iabSend(['message_id' => 77, 'text' => 'Salom'], $user->telegram_id);
        iabSend(iabPhoto(['message_id' => 78]), $user->telegram_id);
    }

    $snapshot = fn () => [
        TelegramMessage::query()->count(),
        Bus::dispatched(ForwardTelegramMedia::class)->count(),
        Bus::dispatched(DeliverBotMessage::class)->count(),
        Event::dispatched(TelegramInboxMessage::class)->count(),
        TelegramUser::query()->orderBy('id')->pluck('unread_count')->all(),
    ];

    expect(iabInboundCount())->toBe(4)
        ->and($snapshot())->toBe([6, 2, 2, 2, [2, 2]]);

    // Open every ack / alert slot again, so only the duplicate check can stop a second run.
    foreach ($users as $user) {
        Cache::forget(IAB_ACK_MARKER.$user->id);
    }
    TelegramUser::query()->update(['last_alert_at' => null]);

    foreach ($users as $user) {
        expect(iabSend(['message_id' => 77, 'text' => 'Salom again'], $user->telegram_id))->toBe(HandleTelegramUpdate::PROCESSED);
        iabSend(iabPhoto(['message_id' => 78]), $user->telegram_id);
    }

    expect($snapshot())->toBe([6, 2, 2, 2, [2, 2]])
        ->and(TelegramMessage::query()->where('text', 'Salom again')->count())->toBe(0);
});

// 2. A replayed update (same update_id) must not queue a second copy.
it('case 2: handles a replayed media update once: one row, one forward job', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    iabUser();
    $update = iabUpdate(iabPhoto(['message_id' => 90]));

    postTelegramUpdate($update)->assertOk();
    postTelegramUpdate($update)->assertOk();
    postTelegramUpdate($update)->assertOk();

    expect(iabInboundCount())->toBe(1)
        ->and(TelegramMessage::query()->where('direction', TelegramDirection::Out)->count())->toBe(1)
        ->and(TelegramUpdate::query()->where('update_id', $update['update_id'])->count())->toBe(1);
    Bus::assertDispatchedTimes(ForwardTelegramMedia::class, 1);
});

/* ------------------------------------------------ 3-5 who may use the inbox */

// 3. Only a private chat whose chat.id is the sender counts.
it('case 3: stores and forwards nothing from a group or a chat that is not the sender\'s own', function (array $override) {
    iabUser();

    foreach ([['text' => 'Need a quote'], iabPhoto(['caption' => 'invoice'])] as $content) {
        expect(iabSend(array_merge($content, $override)))->toBe(HandleTelegramUpdate::IGNORED);
    }

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(0)
        ->and(telegramApiCalls())->toBe([]);
})->with([
    'group' => [['chat' => ['id' => -100123, 'type' => 'group', 'title' => 'G']]],
    'supergroup' => [['chat' => ['id' => -1001234567, 'type' => 'supergroup', 'title' => 'G']]],
    'channel' => [['chat' => ['id' => -1001234568, 'type' => 'channel', 'title' => 'C']]],
    'private chat of someone else' => [['chat' => ['id' => IAB_OTHER, 'type' => 'private']]],
    'type private, id of a group' => [['chat' => ['id' => -100123, 'type' => 'private']]],
    'registered chat, other sender' => [['from' => ['id' => IAB_OTHER, 'is_bot' => false, 'first_name' => 'Eve']]],
]);

// 4. A bot account is never a customer, even with a registered user's id.
it('case 4: stores nothing from an is_bot sender that uses a registered id', function (mixed $isBot) {
    iabUser();
    $from = ['id' => IAB_USER, 'is_bot' => $isBot, 'first_name' => 'Ali'];

    foreach ([['text' => 'Need a quote'], iabPhoto()] as $content) {
        expect(iabSend(array_merge($content, ['from' => $from])))->toBe(HandleTelegramUpdate::IGNORED);
    }

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(0)
        ->and(telegramApiCalls())->toBe([]);
})->with(['true' => true, 'integer' => 1, 'string' => 'false']);

// 5. A banned customer is silent: no row, no unread, no ack, no alert, no forward.
it('case 5: ignores text and media of a banned registered user completely', function () {
    TelegramUser::factory()->registered()->banned()->create(['telegram_id' => IAB_USER, 'locale' => 'en']);

    expect(iabSend(['text' => 'Need a quote']))->toBe(HandleTelegramUpdate::PROCESSED);
    iabSend(iabPhoto(['caption' => 'invoice']));
    iabPost(iabDocument())->assertOk();

    $user = TelegramUser::query()->sole();

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and($user->unread_count)->toBe(0)
        ->and($user->last_message_at)->toBeNull()
        ->and($user->last_alert_at)->toBeNull()
        ->and(telegramApiCalls())->toBe([]);
});

/* -------------------------------------------- 6-7 jobs after ban / kill switch */

// 6. Jobs queued before a ban must not reach Telegram afterwards.
it('case 6: sends no alert, ack or copy for messages taken in before the user was banned', function () {
    Bus::fake([ForwardTelegramMedia::class, DeliverBotMessage::class, SendTelegramMessage::class]);
    Event::fake([TelegramInboxMessage::class]);
    $user = iabUser();

    iabSend(['text' => IAB_SECRET]);
    iabSend(iabPhoto());

    $text = TelegramMessage::query()->where('kind', TelegramMessageKind::Text)->where('direction', TelegramDirection::In)->sole();
    $photo = TelegramMessage::query()->where('kind', TelegramMessageKind::Photo)->sole();
    $ack = TelegramMessage::query()->where('direction', TelegramDirection::Out)->sole();

    $user->forceFill(['banned_at' => now()])->save();
    $client = app(TelegramClient::class);

    (new DeliverBotMessage($ack->id))->handle($client);
    (new ForwardTelegramMedia($photo->id, $user->id))->handle($client);
    app(SendTelegramInboxAlert::class)->handle(new TelegramInboxMessage($text->id));

    expect((new TelegramInboxMessage($text->id))->telegramMessage('uz'))->toBeNull()
        ->and(telegramApiCalls())->toBe([])
        ->and($ack->fresh()->status)->toBe(TelegramMessageStatus::Pending)
        ->and($photo->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending);
    Bus::assertNotDispatched(SendTelegramMessage::class);
});

// 7. Kill switch (intake level; the job level is in TelegramInboxTest).
it('case 7: takes in nothing and queues nothing while the bot is switched off', function () {
    iabBotOn(false);
    Bus::fake();
    Event::fake([TelegramInboxMessage::class]);
    iabUser();

    expect(iabSend(['text' => 'Need a quote']))->toBe(HandleTelegramUpdate::DISABLED)
        ->and(iabSend(iabPhoto()))->toBe(HandleTelegramUpdate::DISABLED)
        ->and(iabPost(iabDocument())->status())->toBe(200)
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(0)
        ->and(telegramApiCalls())->toBe([]);
    Bus::assertNothingDispatched();
    Event::assertNotDispatched(TelegramInboxMessage::class);
});

/* -------------------------------------------------------- 8 rate limits */

// 8. Per-user limits: 20 updates a minute, 300 a day.
it('case 8: drops the 21st update of a minute with one notice and keeps unread and forwards unchanged', function () {
    iabUser();

    foreach (range(1, BotLimits::PER_MINUTE) as $i) {
        iabSend(['text' => "message {$i}"]);
    }

    iabSend(iabPhoto());
    iabSend(['text' => 'over the limit']);
    iabSend(iabDocument());

    $notices = collect(iabCalls('sendMessage', (string) IAB_USER))->where('data.text', __('telegram_bot.rate_limited', [], 'en'));

    expect(iabInboundCount())->toBe(BotLimits::PER_MINUTE)
        ->and(TelegramMessage::query()->where('text', 'over the limit')->exists())->toBeFalse()
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(BotLimits::PER_MINUTE)
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and($notices)->toHaveCount(1);
});

it('case 8: drops the 301st update of a day with one notice and keeps unread and forwards unchanged', function () {
    iabUser();

    foreach (range(1, BotLimits::PER_DAY - 1) as $i) {
        RateLimiter::hit(BotLimits::userKey(IAB_USER, 'day'), 86400);
    }

    iabSend(['text' => 'the 300th']);
    iabSend(iabPhoto());
    iabSend(['text' => 'the 302nd']);

    $notices = collect(iabCalls('sendMessage', (string) IAB_USER))->where('data.text', __('telegram_bot.rate_limited', [], 'en'));

    expect(iabInboundCount())->toBe(1)
        ->and(iabLastInbound()->text)->toBe('the 300th')
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(1)
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and($notices)->toHaveCount(1);
});

/* --------------------------------------------- 9 commands and menu labels */

// 9. Commands steer the bot; they are never customer messages.
it('case 9: stores no alert, ack or row for commands and menu labels of a registered user', function (mixed $text) {
    iabUser();

    iabSend(['text' => value($text)]);

    $user = TelegramUser::query()->sole();

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and($user->unread_count)->toBe(0)
        ->and($user->last_alert_at)->toBeNull()
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and(iabCalls('copyMessage'))->toBe([]);
})->with([
    'start with payload' => '/start secret',
    'start in capitals' => '/START',
    'start for the bot' => '/start@PosonBot',
    'unknown command' => '/x',
    'language' => '/language',
    'help' => '/help',
    'contact manager (uz)' => fn () => __('telegram_bot.menu.contact_manager', [], 'uz'),
    'catalog (ru)' => fn () => __('telegram_bot.menu.catalog', [], 'ru'),
    'language button (en)' => fn () => __('telegram_bot.menu.language', [], 'en'),
]);

/* ------------------------------------------------- 10-12 payload shapes */

// 10. A forged shape never reaches the row and never throws.
it('case 10: answers 200 and stores none of the type-tampered or unknown media params', function (array $message, array $expected) {
    iabUser();
    $update = iabUpdate($message);

    postTelegramUpdate($update)->assertOk();

    $row = iabLastInbound();

    expect(TelegramUpdate::query()->where('update_id', $update['update_id'])->exists())->toBeTrue()
        ->and($row->media)->toEqual($expected === [] ? null : $expected)
        ->and(json_encode($row->getAttributes()))->not->toContain('LEAKED')->not->toContain('file-id')->not->toContain('unique');
})->with([
    'file_name array' => [iabDocument(['file_name' => ['a.pdf']]), ['mime_type' => 'application/pdf', 'file_size' => 52000]],
    'file_name bool' => [iabDocument(['file_name' => true]), ['mime_type' => 'application/pdf', 'file_size' => 52000]],
    'mime_type int' => [iabDocument(['mime_type' => 5]), ['file_name' => 'rekvizitlar.pdf', 'file_size' => 52000]],
    'mime_type array' => [iabDocument(['mime_type' => ['application/pdf']]), ['file_name' => 'rekvizitlar.pdf', 'file_size' => 52000]],
    'file_size string' => [iabDocument(['file_size' => '52000']), ['file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf']],
    'file_size negative' => [iabDocument(['file_size' => -1]), ['file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf']],
    'file_size float' => [iabDocument(['file_size' => 52000.5]), ['file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf']],
    'file_size array' => [iabDocument(['file_size' => [1]]), ['file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf']],
    'every param tampered' => [iabDocument(['file_name' => [], 'mime_type' => 1, 'file_size' => 'x']), []],
    'empty photo list' => [['photo' => []], []],
    'photo is a string' => [['photo' => 'LEAKED'], []],
    'photo is true' => [['photo' => true], []],
    'photo size is a string' => [['photo' => ['LEAKED', 'LEAKED']], []],
    'video is a list of lists' => [['video' => [['file_id' => 'LEAKED', 'duration' => 5]]], []],
    'unknown keys' => [iabDocument(['file_id' => 'LEAKED-ID', 'thumbnail' => ['file_id' => 'LEAKED-THUMB', 'file_size' => 9], 'thumb' => ['file_id' => 'LEAKED-THUMB'], 'file_path' => '/LEAKED', 'url' => 'https://LEAKED.example']), ['file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf', 'file_size' => 52000]],
    'very long file name' => [iabDocument(['file_name' => str_repeat('a', 400).'.pdf']), ['file_name' => str_repeat('a', 255), 'mime_type' => 'application/pdf', 'file_size' => 52000]],
]);

// 11. Only the text and the sanitized media params are kept.
it('case 11: keeps no forward origin, reply, quote or external reply in the row, the alert or the copy', function (array $content) {
    $user = iabUser();
    $metadata = [
        'forward_origin' => ['type' => 'user', 'sender_user' => ['id' => 99, 'first_name' => 'FWD-SECRET-NAME'], 'date' => 1],
        'forward_from' => ['id' => 99, 'first_name' => 'FWD-SECRET-FROM'],
        'forward_date' => 1,
        'reply_to_message' => ['message_id' => 5, 'text' => 'REPLY-SECRET', 'from' => ['id' => 7, 'first_name' => 'REPLY-SECRET-NAME']],
        'quote' => ['text' => 'QUOTE-SECRET', 'position' => 0],
        'external_reply' => ['origin' => ['type' => 'channel', 'chat' => ['id' => -100, 'title' => 'EXTERNAL-SECRET']]],
        'entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'https://evil.example/ENTITY-SECRET']],
        'caption_entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'https://evil.example/ENTITY-SECRET']],
    ];

    iabSend(array_merge($content, $metadata));

    $stored = json_encode([TelegramMessage::query()->get()->toArray(), $user->fresh()->toArray()]);
    $sent = json_encode(telegramApiCalls());

    expect(iabInboundCount())->toBe(1);

    foreach (['FWD-SECRET', 'REPLY-SECRET', 'QUOTE-SECRET', 'EXTERNAL-SECRET', 'ENTITY-SECRET', 'evil.example'] as $needle) {
        expect($stored)->not->toContain($needle)
            ->and($sent)->not->toContain($needle);
    }
})->with([
    'text' => [['text' => 'Need a scanner']],
    'photo with caption' => [iabPhoto(['caption' => 'Need a scanner'])],
]);

// 12. Service messages and unknown objects are "other": nothing kept, nothing forwarded.
it('case 12: stores service and unknown message kinds as other without content and forwards none', function (array $message) {
    iabUser();

    iabSend($message);

    $row = iabLastInbound();

    expect($row->kind)->toBe(TelegramMessageKind::Other)
        ->and($row->text)->toBeNull()
        ->and($row->media)->toBeNull()
        ->and($row->media_group_id)->toBeNull()
        ->and($row->forward_status)->toBeNull()
        ->and(json_encode($row->getAttributes()))->not->toContain('SECRET')
        ->and(TelegramMessage::query()->where('direction', TelegramDirection::Out)->count())->toBe(0)
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([]);
})->with([
    'web_app_data' => [['web_app_data' => ['data' => 'SECRET-payload', 'button_text' => 'SECRET-button']]],
    'successful_payment' => [['successful_payment' => ['currency' => 'UZS', 'total_amount' => 100, 'invoice_payload' => 'SECRET-order']]],
    'pinned_message' => [['pinned_message' => ['message_id' => 1, 'text' => 'SECRET-pinned']]],
    'new_chat_members' => [['new_chat_members' => [['id' => 9, 'is_bot' => false, 'first_name' => 'SECRET-member']]]],
    'dice' => [['dice' => ['emoji' => "\u{1F3B2}", 'value' => 3]]],
    'game' => [['game' => ['title' => 'SECRET-game', 'description' => 'SECRET', 'photo' => []]]],
    'invoice' => [['invoice' => ['title' => 'SECRET-invoice', 'description' => 'SECRET', 'currency' => 'UZS', 'total_amount' => 100]]],
]);

/* ------------------------------------------------ 13-16 alert and header text */

// 13. Cut before e(), never after: the HTML stays balanced.
it('case 13: keeps the alert HTML balanced and escaped when the 300 character cut lands inside markup', function (string $text) {
    iabUser();

    iabSend(['text' => $text]);

    $html = iabAlertTexts()[0];
    $lines = explode("\n", $html);
    $excerpt = html_entity_decode($lines[2], ENT_QUOTES | ENT_HTML5);

    expect($lines)->toHaveCount(3)
        ->and(str_replace(['<b>', '</b>'], '', $html))->not->toContain('<')->not->toContain('>')
        ->and(substr_count($html, '<b>'))->toBe(2)
        ->and(substr_count($html, '</b>'))->toBe(2)
        ->and(preg_match('/&(?!(?:amp|lt|gt|quot|#039);)/', $html))->toBe(0)
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(300)
        ->and($excerpt)->toEndWith('…')
        ->and(str_starts_with($text, mb_substr($excerpt, 0, -1)))->toBeTrue();
})->with([
    'cut inside an anchor tag' => [str_repeat('x', 296).'<a href="https://evil.example">Click</a>'],
    'cut inside ampersands' => [str_repeat('x', 297).'&&&&&&&&'],
    'angle brackets only' => [str_repeat('<', 400)],
    'quotes and brackets' => [str_repeat('"<&', 150)],
]);

// 14. Names and texts are data, never lang placeholders.
it('case 14: keeps :id and :name literal in the alert and the header and prints the right chat number', function () {
    TelegramUser::factory()->count(6)->create();
    $user = iabUser(['name' => 'Ali :id :name :ID :Name']);

    iabSend(['text' => 'need :id and :name and :ID']);
    iabSend(iabPhoto());

    $texts = collect(iabCalls('sendMessage', IAB_SALES_CHAT))->pluck('data.text');
    $chat = e(__('telegram.inbox.chat', ['id' => $user->id], 'uz'));

    expect($user->id)->toBeGreaterThan(6)
        ->and($texts)->toHaveCount(2);

    foreach ($texts as $text) {
        expect($text)->toContain('Ali :id :name :ID :Name · '.$chat)
            ->not->toContain('Ali '.$user->id);
    }

    expect($texts[0])->toContain('need :id and :name and :ID');
});

// 15. Telegram counts captions in UTF-16 code units.
it('case 15: copies a caption of at most 1024 UTF-16 units without splitting a character and keeps line breaks', function (string $caption, string $prefix, int $emoji) {
    iabUser();

    iabSend(iabPhoto(['caption' => $caption]));

    $copied = iabCalls('copyMessage')[0]['data']['caption'];
    $units = strlen((string) mb_convert_encoding($copied, 'UTF-16BE', 'UTF-8')) / 2;

    expect(mb_strlen(iabLastInbound()->text))->toBeLessThanOrEqual(1024)
        ->and(mb_check_encoding((string) iabLastInbound()->text, 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($copied, 'UTF-8'))->toBeTrue()
        ->and($units)->toBeLessThanOrEqual(1024)
        ->and($copied)->toStartWith($prefix)->not->toContain("\r")
        ->and(mb_substr_count($copied, "\u{1F600}"))->toBe($emoji)
        ->and(mb_strlen($copied))->toBe(mb_strlen($prefix) + $emoji);
})->with([
    'even offset, line breaks' => ["Row1\r\nRow2\n".str_repeat("\u{1F600}", 600), "Row1\nRow2\n", 507],
    'odd offset (a pair would straddle the limit)' => ['a'.str_repeat("\u{1F600}", 600), 'a', 511],
]);

// 16. PII stays in the CRM.
it('case 16: keeps phone, company, INN, username and Telegram id out of the alert', function () {
    $user = iabUser([
        'phone' => '+998935556677', 'declared_company' => 'Zebra Secret LLC', 'declared_inn' => '305123456', 'username' => 'secret_handle',
    ], 7771234);

    iabSend(['text' => 'Need a scanner', 'from' => ['id' => 7771234, 'is_bot' => false, 'first_name' => 'Ali', 'last_name' => 'Karimov', 'username' => 'secret_handle']], 7771234);

    $sent = json_encode(iabCalls('sendMessage', IAB_SALES_CHAT));

    expect($user->fresh()->username)->toBe('secret_handle')
        ->and(iabAlertTexts())->toHaveCount(1)
        ->and(iabAlertTexts()[0])->toContain('Need a scanner');

    foreach (['998935556677', '935556677', 'Zebra', 'Secret LLC', '305123456', 'secret_handle', '7771234', 'Karimov'] as $needle) {
        expect($sent)->not->toContain($needle);
    }
});

/* ------------------------------------------------------ 17-19 the target */

// 17. A group that went public after a good check is caught on the next check.
it('case 17: stops copying once the cached target turns public: failed 400, cache dropped, then not configured with one alert', function () {
    iabUser();

    iabSend(iabPhoto());
    iabApi(['copyMessage' => 400, 'getChat' => ['id' => -1, 'type' => 'supergroup', 'username' => 'went_public']]);
    iabSend(iabPhoto());
    iabSend(iabPhoto());
    iabSend(iabDocument());

    $statuses = TelegramMessage::query()->where('direction', TelegramDirection::In)->orderBy('id')->pluck('forward_status')->all();

    expect($statuses)->toBe([TelegramForwardStatus::Forwarded, TelegramForwardStatus::Failed, TelegramForwardStatus::NotConfigured, TelegramForwardStatus::NotConfigured])
        ->and(iabCalls('copyMessage'))->toHaveCount(2)
        ->and(iabCalls('getChat'))->toHaveCount(2)
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toHaveCount(1)
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(1)
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT)[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.refused_target', [], 'uz')));
});

// 18. Same through the real webhook route.
it('case 18: keeps text of a refused target stored, sends nothing to it and raises one monitoring alert', function () {
    iabApi(['getChat' => ['id' => -1, 'type' => 'supergroup', 'username' => 'open_group']]);
    iabUser();

    iabPost(['text' => 'first '.IAB_SECRET])->assertOk();
    iabPost(['text' => 'second '.IAB_SECRET])->assertOk();
    $this->travel(11)->minutes();
    iabPost(['text' => 'third '.IAB_SECRET])->assertOk();

    $alerts = iabCalls('sendMessage', TG_MANAGEMENT_CHAT);

    expect(iabInboundCount(TelegramMessageKind::Text))->toBe(3)
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.refused_target', [], 'uz')))
        ->and(json_encode($alerts))->not->toContain('SECRET');
});

// 19. A sync queue lets the listener's transient failure reach the controller.
it('case 19: answers 200 and leaks nothing when the inbox chat check fails transiently, then alerts once it recovers', function (int $status) {
    $logs = iabCaptureLogs();
    iabUser(['name' => 'Private Name']);
    iabApi(['getChat' => $status]);
    $update = iabUpdate(['text' => IAB_SECRET]);

    postTelegramUpdate($update)->assertOk()->assertExactJson(['ok' => true]);

    $log = implode("\n", $logs());
    $row = iabLastInbound();

    expect(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and($log)->toContain('"telegram_message_id":'.$row->id)->toContain((string) $status)
        ->and($log)->not->toContain('SECRET')->not->toContain('Private Name')->not->toContain('998901234567')
        ->and($log)->not->toContain('ali_secret_username')->not->toContain((string) IAB_USER)->not->toContain(IAB_SALES_CHAT)
        ->and($log)->not->toContain(TG_BOT_TOKEN)->not->toContain(TG_WEBHOOK_SECRET);

    // Nothing was cached and the alert slot is free again: the next text alerts.
    iabApi(['getChat' => null]);
    iabPost(['text' => 'after recovery'])->assertOk();

    expect(collect(iabAlertTexts())->filter(fn (string $text) => str_contains($text, 'after recovery')))->toHaveCount(1);
})->with([502, 429]);

/* ----------------------------------------------------- 20-24 forward job */

// 20. The job payload is two ids: whatever they point at is re-checked.
it('case 20: ignores a forged or stale forward job: no Telegram call, status unchanged', function (Closure $scenario) {
    $owner = iabUser();
    $other = iabUser([], IAB_OTHER);
    [$messageId, $userId] = $scenario($owner, $other);
    $before = TelegramMessage::query()->find($messageId)?->only(['forward_status', 'forwarded_message_id', 'forward_error_code']);

    (new ForwardTelegramMedia($messageId, $userId))->handle(app(TelegramClient::class));

    expect(telegramApiCalls())->toBe([])
        ->and(TelegramMessage::query()->find($messageId)?->only(['forward_status', 'forwarded_message_id', 'forward_error_code']))->toEqual($before)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(0)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($owner->id)))->toBe(0);
})->with([
    'another user\'s id' => [fn (TelegramUser $owner, TelegramUser $other) => [iabMediaRow($owner)->id, $other->id]],
    'outbound row' => [fn (TelegramUser $owner) => [TelegramMessage::factory()->outbound()->media(TelegramMessageKind::Photo)->create(['telegram_user_id' => $owner->id, 'telegram_message_id' => 51])->id, $owner->id]],
    'text row' => [fn (TelegramUser $owner) => [TelegramMessage::factory()->create(['telegram_user_id' => $owner->id, 'telegram_message_id' => 52, 'forward_status' => TelegramForwardStatus::Pending])->id, $owner->id]],
    'no telegram_message_id' => [fn (TelegramUser $owner) => [TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create(['telegram_user_id' => $owner->id, 'telegram_message_id' => null])->id, $owner->id]],
    'deleted row' => [function (TelegramUser $owner) {
        $row = iabMediaRow($owner, 53);
        $row->delete();

        return [$row->id, $owner->id];
    }],
]);

// 21. Refused retries cost nothing.
it('case 21: refuses a retry before touching the queue, the cap marker or the per-minute limit', function (Closure $scenario, bool $forbidden) {
    Bus::fake([ForwardTelegramMedia::class]);
    [$actor, $row] = $scenario();
    Cache::put(ForwardTelegramMedia::capDecisionKey($row->id), 'conversation_limit', 3600);

    if ($forbidden) {
        expect(fn () => app(RetryForward::class)->handle($actor, $row))->toThrow(AuthorizationException::class);
    } else {
        expect(fn () => app(RetryForward::class)->handle($actor, $row))->toThrow(InboxRefused::class);
    }

    Bus::assertNotDispatched(ForwardTelegramMedia::class);
    expect(Cache::get(ForwardTelegramMedia::capDecisionKey($row->id)))->toBe('conversation_limit')
        ->and(RateLimiter::attempts(RetryForward::PER_MINUTE_KEY.$row->id))->toBe(0);
})->with([
    'content manager, unassigned' => [fn () => [staffUser(Role::ContentManager), iabMediaRow(iabUser())->forceFill(['forward_status' => TelegramForwardStatus::Failed])], true],
    'service engineer, unassigned' => [fn () => [staffUser(Role::ServiceEngineer), iabMediaRow(iabUser())->forceFill(['forward_status' => TelegramForwardStatus::Failed])], true],
    'outbound row' => [fn () => [staffUser(), TelegramMessage::factory()->outbound()->media(TelegramMessageKind::Photo)->create(['telegram_user_id' => iabUser()->id, 'telegram_message_id' => 54, 'forward_status' => TelegramForwardStatus::Failed])], false],
    'text row' => [fn () => [staffUser(), TelegramMessage::factory()->create(['telegram_user_id' => iabUser()->id, 'telegram_message_id' => 55, 'forward_status' => TelegramForwardStatus::Failed])], false],
]);

// 22. A model loaded before another worker finished must not trigger a second copy.
it('case 22: never copies twice when a retry starts from a stale failed model', function (TelegramForwardStatus $actual) {
    $admin = staffUser();
    $user = iabUser();
    $row = TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => 61,
        'forward_status' => $actual, 'forwarded_message_id' => $actual === TelegramForwardStatus::Forwarded ? 555 : null,
    ]);
    $stale = $row->fresh();
    $stale->forward_status = TelegramForwardStatus::Failed;

    expect(fn () => app(RetryForward::class)->handle($admin, $stale))->toThrow(InboxRefused::class);

    expect(iabCalls('copyMessage'))->toBe([])
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and($row->fresh()->forward_status)->toBe($actual)
        ->and($row->fresh()->forwarded_message_id)->toBe($actual === TelegramForwardStatus::Forwarded ? 555 : null);
})->with([TelegramForwardStatus::Forwarded, TelegramForwardStatus::Sending]);

// 23. Staff retries obey the same cap.
it('case 23: keeps a staff retry over the hourly cap limited: no copy and no header', function () {
    $admin = staffUser();
    $user = iabUser();

    foreach (range(1, ForwardTelegramMedia::CONVERSATION_PER_HOUR) as $i) {
        RateLimiter::hit(ForwardTelegramMedia::conversationKey($user->id), 3600);
    }

    iabSend(iabPhoto());
    $row = iabLastInbound();

    expect($row->forward_status)->toBe(TelegramForwardStatus::Limited);

    expect(app(RetryForward::class)->handle($admin, $row->fresh()))->toBeTrue();

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Limited)
        ->and($user->fresh()->forward_header_message_id)->toBeNull()
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(1);
});

// 24. One noisy conversation must not eat the shared daily budget.
it('case 24: lets other conversations forward while one is over its hourly cap and spends no daily budget on it', function () {
    $noisy = iabUser();
    iabUser([], IAB_OTHER);

    foreach (range(1, ForwardTelegramMedia::CONVERSATION_PER_HOUR) as $i) {
        RateLimiter::hit(ForwardTelegramMedia::conversationKey($noisy->id), 3600);
    }

    foreach (range(1, 3) as $i) {
        iabSend(iabPhoto());
    }

    expect(TelegramMessage::query()->where('forward_status', TelegramForwardStatus::Limited)->count())->toBe(3)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(0);

    iabSend(iabPhoto(), IAB_OTHER);

    expect(iabLastInbound()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and(iabCalls('copyMessage'))->toHaveCount(1)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(1);
});

/* ------------------------------------------------ 25-26 state under races */

// 25. Two intakes that loaded the same row before either wrote.
it('case 25: raises one alert and counts both messages when two intakes start from the same stale model', function () {
    Bus::fake([DeliverBotMessage::class]);
    Event::fake([TelegramInboxMessage::class]);
    $user = iabUser(['unread_count' => 0, 'last_alert_at' => null]);
    $first = TelegramUser::query()->findOrFail($user->id);
    $second = TelegramUser::query()->findOrFail($user->id);
    $store = app(StoreInboundMessage::class);

    $store->handle($first, UpdateParser::fromArray(iabUpdate(['message_id' => 201, 'text' => 'one'])));
    $store->handle($second, UpdateParser::fromArray(iabUpdate(['message_id' => 202, 'text' => 'two'])));

    expect($user->fresh()->unread_count)->toBe(2)
        ->and($user->fresh()->last_alert_at)->not->toBeNull();
    Event::assertDispatchedTimes(TelegramInboxMessage::class, 1);
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 1);
});

// 26. Markers are per conversation.
it('case 26: gives every user their own ack, unsupported notice and alert', function () {
    $first = iabUser();
    $second = iabUser([], IAB_OTHER);
    $contact = ['contact' => ['phone_number' => '+998909012345', 'first_name' => 'X']];

    foreach ([IAB_USER, IAB_OTHER] as $telegramId) {
        iabSend(['text' => 'Need a quote'], $telegramId);
        iabSend($contact, $telegramId);
    }

    $acks = TelegramMessage::query()->where('direction', TelegramDirection::Out)->orderBy('telegram_user_id')->pluck('telegram_user_id')->all();
    $alerts = collect(iabAlertTexts());

    expect($acks)->toBe([$first->id, $second->id]);

    foreach ([[$first, IAB_USER], [$second, IAB_OTHER]] as [$user, $telegramId]) {
        $toCustomer = collect(iabCalls('sendMessage', (string) $telegramId))->pluck('data.text');

        expect($toCustomer->filter(fn (string $text) => $text === __('telegram_bot.inbox.auto_ack', [], 'en')))->toHaveCount(1)
            ->and($toCustomer->filter(fn (string $text) => $text === __('telegram_bot.inbox.unsupported', [], 'en')))->toHaveCount(1)
            ->and($alerts->filter(fn (string $text) => str_contains($text, e(__('telegram.inbox.chat', ['id' => $user->id], 'uz')))))->toHaveCount(1)
            ->and($user->fresh()->last_alert_at)->not->toBeNull();
    }

    expect($alerts)->toHaveCount(2);
});

/* ----------------------------------------------------------- 27 failures */

// 27. A failing intake rolls back completely and reports no payload.
it('case 27: answers 200, rolls the whole intake back and queues nothing when the database fails', function (array $message) {
    $user = iabUser();
    iabFailSecondInsert();

    iabPost($message)->assertOk()->assertExactJson(['ok' => true]);

    $user->refresh();

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->count())->toBe(0)
        ->and($user->unread_count)->toBe(0)
        ->and($user->last_message_at)->toBeNull()
        ->and($user->last_alert_at)->toBeNull()
        ->and(telegramApiCalls())->toBe([]);
})->with([
    'text (alert queued before the failure)' => [['text' => IAB_SECRET]],
    'photo (forward queued before the failure)' => [iabPhoto(['caption' => IAB_SECRET])],
]);

it('case 27: reports only the update id and the exception class, and logs nothing from the customer', function () {
    $logs = iabCaptureLogs();
    iabUser();
    iabFailSecondInsert();
    $update = iabUpdate(['text' => IAB_SECRET]);

    postTelegramUpdate($update)->assertOk();

    $log = implode("\n", $logs());

    expect($log)->toContain('Telegram update '.$update['update_id'].' failed: '.QueryException::class)
        ->not->toContain('SECRET')->not->toContain('998901234567')
        ->not->toContain('telegram_messages')->not->toContain('SQLSTATE')
        ->not->toContain(TG_BOT_TOKEN)->not->toContain(TG_WEBHOOK_SECRET);
});

it('case 27: hands the exception handler a TelegramUpdateFailed without payload or cause', function () {
    Exceptions::fake();
    iabUser();
    iabFailSecondInsert();
    $update = iabUpdate(['text' => IAB_SECRET]);

    postTelegramUpdate($update)->assertOk();

    Exceptions::assertReported(fn (TelegramUpdateFailed $failed) => $failed->updateId === $update['update_id']
        && $failed->exceptionClass === QueryException::class
        && $failed->getPrevious() === null
        && ! str_contains($failed->getMessage(), 'SECRET'));
    Exceptions::assertReportedCount(1);
});

/* ---------------------------------------------- 28 registration in progress */

// 28. Nothing of a half-registered visitor reaches the inbox.
it('case 28: stores and forwards nothing from a user who is still registering', function (TelegramUserState $state, array $message) {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => IAB_USER, 'locale' => 'en', 'state' => $state]);

    iabSend($message);

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and(TelegramUser::query()->sole()->unread_count)->toBe(0)
        ->and(iabCalls('copyMessage'))->toBe([])
        ->and(iabCalls('sendMessage', IAB_SALES_CHAT))->toBe([])
        ->and(iabCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([]);
})->with([
    TelegramUserState::Language,
    TelegramUserState::Contact,
    TelegramUserState::Name,
    TelegramUserState::Company,
    TelegramUserState::Inn,
])->with([
    'text' => [['text' => 'Need a quote']],
    'photo' => [iabPhoto(['caption' => 'invoice'])],
    'document' => [iabDocument()],
    'location' => [['location' => ['latitude' => 41.3, 'longitude' => 69.2]]],
]);

/* ------------------------------------------------------ 29 settings access */

// 29. The inbox switches are Admin-only settings (generic access tests: Admin/Telegram/SettingsScreenTest "access").
it('case 29: refuses telegram.notify_inbox and telegram.chats.inbox changes to other staff roles', function (Role $role) {
    confirmAdminPassword();
    $component = Livewire::actingAs(staffUser())->test(TelegramSettings::class)
        ->set('form.values.telegram.notify_inbox', false)
        ->set('form.values.telegram.chats.inbox', '-1002222222222');

    $this->actingAs(staffUser($role));

    $component->call('save', 'telegram')->assertForbidden();

    expect(Setting::query()->whereIn('key', ['telegram.notify_inbox', 'telegram.chats.inbox'])->count())->toBe(0)
        ->and(SettingsAudit::query()->count())->toBe(0)
        ->and(config('services.telegram.notify_inbox'))->not->toBeFalse();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('case 29: audits an Admin change of the inbox switches by key, never with the value', function () {
    confirmAdminPassword();
    $admin = staffUser();

    Livewire::actingAs($admin)->test(TelegramSettings::class)
        ->set('form.values.telegram.notify_inbox', false)
        ->set('form.values.telegram.chats.inbox', '-1002222222222')
        ->call('save', 'telegram')
        ->assertHasNoErrors();

    $audits = SettingsAudit::query()->where('area', SettingsArea::Integrations)->get();

    expect($audits->pluck('key')->sort()->values()->all())->toBe(['telegram.chats.inbox', 'telegram.notify_inbox'])
        ->and($audits->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and(json_encode($audits->toArray()))->not->toContain('1002222222222');
});
