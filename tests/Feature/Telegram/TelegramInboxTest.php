<?php

use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\RetryForward;
use App\Actions\Telegram\StoreInboundMessage;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Events\Contracts\TelegramAlert;
use App\Events\Orders\OrderPlaced;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendBotPrompt;
use App\Listeners\SendTelegramAlerts;
use App\Listeners\SendTelegramInboxAlert;
use App\Models\Order;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\ForwardAlerts;
use App\Support\Telegram\Bot\UpdateParser;
use App\Support\Telegram\InboxMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Plan 14 v2 B4: inbox intake (StoreInboundMessage), auto-ack, the
 * TelegramInboxMessage alert and ForwardTelegramMedia (TG-21…26,
 * B4-S1…S11). Bot API faked (fake token), queue sync.
 */
const INBOX_USER = 5550001;

const INBOX_SALES_CHAT = '-1001111111111';

const INBOX_CHAT = '-1002222222222';

function inboxBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

/**
 * Fake Bot API by method. A response may be an array (ok body), an int (error
 * status) or a closure returning either.
 *
 * @param  array<string, mixed>  $responses
 */
function inboxApi(array $responses = []): void
{
    $faked = app()->bound('inbox.api');
    app()->instance('inbox.api', $responses);

    if ($faked) {
        return;
    }

    Http::fake(['api.telegram.org/*' => function (Request $request) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $response = app('inbox.api')[$method] ?? match ($method) {
            'getChat' => ['id' => (int) ($request->data()['chat_id'] ?? 0), 'type' => 'supergroup', 'title' => 'Team'],
            'sendMessage' => ['message_id' => inboxNextSentId((string) ($request->data()['chat_id'] ?? ''))],
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
function inboxCalls(?string $method = null, ?string $chatId = null): array
{
    return collect(telegramApiCalls())
        ->filter(fn (array $call) => $method === null || $call['method'] === $method)
        ->filter(fn (array $call) => $chatId === null || (string) ($call['data']['chat_id'] ?? '') === $chatId)
        ->values()->all();
}

/**
 * Message ids per chat as Telegram gives them: 900, 901, … in each chat.
 */
function inboxNextSentId(string $chatId): int
{
    $ids = app()->bound('inbox.sent') ? app('inbox.sent') : [];
    $ids[$chatId] = ($ids[$chatId] ?? 899) + 1;
    app()->instance('inbox.sent', $ids);

    return $ids[$chatId];
}

function inboxNextUpdateId(): int
{
    static $id = 5000;

    return ++$id;
}

function inboxUser(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge([
        'telegram_id' => INBOX_USER,
        'name' => 'Ali Valiyev',
        'username' => 'ali_secret_username',
        'declared_company' => 'OOO Secret Company',
        'declared_inn' => '123456789',
        'locale' => 'en',
    ], $attributes));
}

/**
 * Send a private message update (no "text" unless given) through the bot.
 *
 * @param  array<string, mixed>  $message
 */
function inboxSend(array $message, int $userId = INBOX_USER): string
{
    $update = telegramPrivateUpdate(inboxNextUpdateId(), $message, $userId);

    if (! array_key_exists('text', $message)) {
        unset($update['message']['text']);
    }

    return app(HandleTelegramUpdate::class)->handle($update);
}

function inboxPhoto(array $extra = []): array
{
    return array_merge(['photo' => [
        ['file_id' => 'small-file-id', 'file_unique_id' => 'small-unique', 'width' => 90, 'height' => 60, 'file_size' => 1200],
        ['file_id' => 'big-file-id', 'file_unique_id' => 'big-unique', 'width' => 1280, 'height' => 960, 'file_size' => 90000],
    ]], $extra);
}

function inboxDocument(array $document = [], array $extra = []): array
{
    return array_merge(['document' => array_merge([
        'file_id' => 'doc-file-id', 'file_unique_id' => 'doc-unique', 'file_name' => 'rekvizitlar.pdf', 'mime_type' => 'application/pdf', 'file_size' => 52000,
    ], $document)], $extra);
}

function inboxLastInbound(): TelegramMessage
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)->latest('id')->firstOrFail();
}

/**
 * Run the forward job like a worker would (its middleware, fake queue
 * interactions); a thrown Telegram error is swallowed (the worker retries).
 */
function inboxRunForward(TelegramMessage $row): ForwardTelegramMedia
{
    $job = (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->withFakeQueueInteractions();

    try {
        $job->middleware()[0]->handle($job, fn (ForwardTelegramMedia $job) => $job->handle(app(TelegramClient::class)));
    } catch (TelegramException) {
        // retried by the worker
    }

    return $job;
}

/**
 * @return Closure(): list<string>
 */
function inboxCaptureLogs(): Closure
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = json_encode([$event->message, $event->context]);
    });

    return function () use (&$logged): array {
        return $logged;
    };
}

beforeEach(function () {
    telegramBotConfigured();
    inboxBotOn();
    inboxApi();
});

/* ---------------------------------------------------------------- intake */

it('stores every forwardable media kind as sanitized metadata without file ids and forwards it', function (array $payload, TelegramMessageKind $kind, array $media) {
    inboxUser();

    inboxSend($payload);

    $row = inboxLastInbound();

    expect($row->kind)->toBe($kind)
        ->and($row->media)->toEqual($media === [] ? null : $media)
        ->and($row->status)->toBe(TelegramMessageStatus::Received)
        ->and($row->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and($row->forwarded_message_id)->toBe(901)
        ->and(json_encode($row->getAttributes()))->not->toContain('file-id')->not->toContain('unique')
        ->and(inboxCalls('copyMessage'))->toHaveCount(1);
})->with([
    'photo (largest size)' => [inboxPhoto(), TelegramMessageKind::Photo, ['file_size' => 90000, 'width' => 1280, 'height' => 960]],
    'document' => [inboxDocument(), TelegramMessageKind::Document, ['file_size' => 52000, 'mime_type' => 'application/pdf', 'file_name' => 'rekvizitlar.pdf']],
    'video' => [['video' => ['file_id' => 'v-file-id', 'file_unique_id' => 'v-unique', 'duration' => 12, 'width' => 640, 'height' => 480, 'mime_type' => 'video/mp4']], TelegramMessageKind::Video, ['mime_type' => 'video/mp4', 'duration' => 12, 'width' => 640, 'height' => 480]],
    'audio' => [['audio' => ['file_id' => 'a-file-id', 'file_unique_id' => 'a-unique', 'duration' => 30, 'mime_type' => 'audio/mpeg', 'file_name' => 'call.mp3']], TelegramMessageKind::Audio, ['mime_type' => 'audio/mpeg', 'file_name' => 'call.mp3', 'duration' => 30]],
    'voice' => [['voice' => ['file_id' => 'vo-file-id', 'file_unique_id' => 'vo-unique', 'duration' => 4, 'mime_type' => 'audio/ogg']], TelegramMessageKind::Voice, ['mime_type' => 'audio/ogg', 'duration' => 4]],
    'video note' => [['video_note' => ['file_id' => 'vn-file-id', 'file_unique_id' => 'vn-unique', 'duration' => 5, 'length' => 240]], TelegramMessageKind::VideoNote, ['duration' => 5]],
    'animation' => [['animation' => ['file_id' => 'an-file-id', 'file_unique_id' => 'an-unique', 'width' => 320, 'height' => 240, 'duration' => 2], 'document' => ['file_id' => 'an-file-id']], TelegramMessageKind::Animation, ['duration' => 2, 'width' => 320, 'height' => 240]],
    'sticker' => [['sticker' => ['file_id' => 's-file-id', 'file_unique_id' => 's-unique', 'width' => 512, 'height' => 512]], TelegramMessageKind::Sticker, ['width' => 512, 'height' => 512]],
    'location (no coordinates kept)' => [['location' => ['latitude' => 41.31, 'longitude' => 69.24]], TelegramMessageKind::Location, []],
]);

it('reduces file names to a clean base name and drops odd mime types (B4-S5)', function (string $name, ?string $expected, string $mime, ?string $expectedMime) {
    inboxUser();

    inboxSend(inboxDocument(['file_name' => $name, 'mime_type' => $mime]));

    $media = inboxLastInbound()->media;

    expect($media['file_name'] ?? null)->toBe($expected)
        ->and($media['mime_type'] ?? null)->toBe($expectedMime);
})->with([
    'RLO trick' => ["invoice\u{202E}fdp.exe", 'invoicefdp.exe', 'application/pdf', 'application/pdf'],
    'parent folder' => ['../x', 'x', 'application/pdf', 'application/pdf'],
    'windows path' => ['C:\\Users\\a\\..\\secret.docx', 'secret.docx', 'text/plain', 'text/plain'],
    'only dots' => ['..', null, 'text/html; charset=<b>', null],
    'zero width' => ["sc\u{200B}an.pdf", 'scan.pdf', 'image/svg+xml', 'image/svg+xml'],
]);

it('keeps the caption (≤1024) of media and the media_group_id', function () {
    inboxUser();

    inboxSend(inboxPhoto(['caption' => str_repeat('a', 1500), 'media_group_id' => '13579246801']));

    $row = inboxLastInbound();

    expect(mb_strlen($row->text))->toBe(1024)
        ->and($row->media_group_id)->toBe('13579246801');
});

it('stores contacts, inline-bot messages, buttons, polls and stories as "other": nothing kept, not forwarded, one hint per 10 minutes (B4-S6, TG-22)', function (array $payload) {
    inboxUser();

    inboxSend($payload);
    inboxSend($payload);

    $rows = TelegramMessage::query()->where('direction', TelegramDirection::In)->get();
    $toCustomer = inboxCalls('sendMessage', (string) INBOX_USER);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('kind')->unique()->all())->toBe([TelegramMessageKind::Other])
        ->and($rows->pluck('text')->filter()->all())->toBe([])
        ->and($rows->pluck('media')->filter()->all())->toBe([])
        ->and($rows->pluck('forward_status')->filter()->all())->toBe([])
        ->and(json_encode($rows->toArray()))->not->toContain('901234')->not->toContain('Hidden')
        ->and(inboxCalls('copyMessage'))->toBe([])
        ->and($toCustomer)->toHaveCount(1)
        ->and($toCustomer[0]['data']['text'])->toBe(__('telegram_bot.inbox.unsupported', [], 'en'));

    $this->travel(11)->minutes();
    inboxSend($payload);

    expect(inboxCalls('sendMessage', (string) INBOX_USER))->toHaveCount(2);
})->with([
    'contact' => [['contact' => ['phone_number' => '+998901234567', 'first_name' => 'Hidden', 'user_id' => INBOX_USER]]],
    'someone else\'s contact' => [['contact' => ['phone_number' => '+998909012345', 'first_name' => 'Hidden']]],
    'via inline bot' => [['text' => 'Hidden offer', 'via_bot' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']]],
    'with buttons' => [inboxPhoto(['caption' => 'Hidden', 'reply_markup' => ['inline_keyboard' => [[['text' => 'Pay', 'url' => 'https://evil.example']]]]])],
    'poll' => [['poll' => ['id' => '1', 'question' => 'Hidden?', 'options' => []]]],
    'story' => [['story' => ['chat' => ['id' => 1], 'id' => 2]]],
    'venue (L5)' => [['venue' => ['location' => ['latitude' => 41.3, 'longitude' => 69.2], 'title' => 'Hidden', 'address' => 'Hidden street'], 'location' => ['latitude' => 41.3, 'longitude' => 69.2]]],
]);

it('counts unread, reopens a closed conversation and never stores the same Telegram message twice', function () {
    $user = inboxUser(['conversation_status' => ConversationStatus::Closed, 'closed_at' => now()->subDay()]);

    inboxSend(['message_id' => 77, 'text' => 'Salom']);
    app(StoreInboundMessage::class)->handle($user->fresh(), UpdateParser::fromArray(telegramPrivateUpdate(inboxNextUpdateId(), ['message_id' => 77, 'text' => 'again'])));

    $user->refresh();

    expect(TelegramMessage::query()->where('direction', TelegramDirection::In)->count())->toBe(1)
        ->and($user->unread_count)->toBe(1)
        ->and($user->conversation_status)->toBe(ConversationStatus::Open)
        ->and($user->closed_at)->toBeNull()
        ->and($user->last_inbound_at)->not->toBeNull()
        ->and($user->last_message_at)->not->toBeNull();
});

it('leaves unregistered users to the registration flow: nothing stored', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => INBOX_USER, 'locale' => 'en']);

    inboxSend(inboxPhoto(['caption' => 'my invoice']));

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and(inboxCalls('copyMessage'))->toBe([]);
});

/* -------------------------------------------------------- auto-ack, alert */

it('auto-acks once per 30 minutes with a stored outbound message that never echoes the text (B4-S10)', function () {
    inboxUser();

    inboxSend(['text' => 'Need <b>Zebra</b> ZD421']);
    inboxSend(inboxPhoto());
    inboxSend(['text' => 'second']);

    $acks = TelegramMessage::query()->where('direction', TelegramDirection::Out)->get();

    expect($acks)->toHaveCount(1)
        ->and($acks[0]->text)->toBe(__('telegram_bot.inbox.auto_ack', [], 'en'))
        ->and($acks[0]->status)->toBe(TelegramMessageStatus::Sent)
        ->and(collect(inboxCalls('sendMessage', (string) INBOX_USER))->pluck('data.text')->all())->toBe([__('telegram_bot.inbox.auto_ack', [], 'en')]);

    $this->travel(31)->minutes();
    inboxSend(['text' => 'third']);

    expect(TelegramMessage::query()->where('direction', TelegramDirection::Out)->count())->toBe(2);
});

it('alerts the inbox chat about text at most once per conversation per 10 minutes, escaped and excerpted (B4-S7, B4-S10)', function () {
    config(['app.url' => 'http://poson.test']);
    inboxUser(['name' => "Ali\u{202E} <b>Boss</b>\nVali"]);

    inboxSend(['text' => '<a href="https://evil.example">Click</a> '.str_repeat('x', 400)]);
    inboxSend(['text' => 'second message']);
    inboxSend(inboxPhoto());

    $alerts = collect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->filter(fn ($call) => str_contains($call['data']['text'], e(__('telegram.inbox.alert_title', [], 'uz'))))->values();
    $text = $alerts[0]['data']['text'];
    $user = TelegramUser::query()->sole();

    expect($alerts)->toHaveCount(1)
        ->and($text)->toContain('<b>'.e(__('telegram.inbox.customer', [], 'uz')).'</b> Ali &lt;b&gt;Boss&lt;/b&gt; Vali')
        ->and($text)->toContain(e(__('telegram.inbox.chat', ['id' => $user->id], 'uz')))
        ->and($text)->toContain('&lt;a href=&quot;https://evil.example&quot;&gt;Click&lt;/a&gt;')
        ->and($text)->not->toContain('<a ')
        ->and($text)->not->toContain((string) INBOX_USER)
        ->and($text)->not->toContain('ali_secret_username')->not->toContain('Secret Company')->not->toContain('123456789')->not->toContain('901234567')
        ->and(mb_strlen(InboxMessage::excerpt('<a>'.str_repeat('x', 400))))->toBe(300)
        ->and($alerts[0]['data'])->not->toHaveKey('reply_markup')
        ->and($user->last_alert_at)->not->toBeNull();

    $this->travel(11)->minutes();
    inboxSend(['text' => 'after ten minutes']);

    expect(collect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->filter(fn ($call) => str_contains($call['data']['text'], 'after ten minutes')))->toHaveCount(1);
});

it('sends the text alert to the same resolved target as files (B4-S1)', function (array $chats, mixed $getChat, ?string $expected, bool $checklist) {
    telegramBotConfigured($chats);
    inboxApi(['getChat' => $getChat]);
    inboxUser();

    inboxSend(['text' => 'hello team']);

    $alerts = collect(telegramApiCalls())
        ->filter(fn (array $call) => $call['method'] === 'sendMessage' && str_contains($call['data']['text'], 'hello team'))
        ->pluck('data.chat_id')->values()->all();

    expect($alerts)->toBe($expected === null ? [] : [$expected])
        ->and(app(BotChecklist::class)->local()['chat'])->toBe($checklist);
})->with([
    'inbox chat' => [['services.telegram.chats.inbox' => INBOX_CHAT], null, INBOX_CHAT, true],
    'sales fallback' => [[], null, INBOX_SALES_CHAT, true],
    '@channel inbox' => [['services.telegram.chats.inbox' => '@poson_files'], null, null, false],
    'public supergroup' => [[], ['id' => -1, 'type' => 'supergroup', 'username' => 'open_group'], null, true],
]);

it('sends no inbox alert when telegram.notify_inbox is off', function () {
    telegramBotConfigured(['services.telegram.notify_inbox' => 'false']);
    inboxUser();

    inboxSend(['text' => 'hello']);

    expect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([])
        ->and(TelegramUser::query()->sole()->last_alert_at)->toBeNull();
});

it('queues everything after commit with ids only', function () {
    Bus::fake();
    Event::fake([TelegramInboxMessage::class]);
    inboxUser();

    inboxSend(inboxDocument(['file_name' => 'secret-name.pdf'], ['caption' => 'secret caption']));
    inboxSend(['text' => 'secret text']);

    Bus::assertDispatched(ForwardTelegramMedia::class, fn (ForwardTelegramMedia $job) => $job->queue === 'default' && ! str_contains(serialize($job), 'secret'));
    Bus::assertDispatched(DeliverBotMessage::class, 1);
    Event::assertDispatched(TelegramInboxMessage::class, 1);

    $job = new ForwardTelegramMedia(1, 2);

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and(new TelegramInboxMessage(1))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($job->backoff())->toBe([30, 120, 600, 1800])
        ->and(config('monitoring.retry_classes'))->toContain(ForwardTelegramMedia::class)
        ->and(config('monitoring.retry_classes'))->not->toContain(DeliverBotMessage::class);
});

/* ------------------------------------------------------------- forwarding */

it('copies with a plain explicit caption, no buttons, as a reply to the header (TG-21, B4-S4)', function () {
    inboxUser();

    inboxSend(inboxPhoto([
        'caption' => "\u{200B}",
        'caption_entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 1, 'url' => 'https://evil.example']],
    ]));

    $copy = inboxCalls('copyMessage')[0]['data'];

    expect($copy)->toMatchArray([
        'chat_id' => INBOX_SALES_CHAT,
        'from_chat_id' => (string) INBOX_USER,
        'caption' => '',
        'caption_entities' => [],
        'reply_markup' => ['inline_keyboard' => []],
        'reply_parameters' => ['message_id' => 900, 'allow_sending_without_reply' => true],
    ])->and($copy)->not->toHaveKey('parse_mode')
        ->and(json_encode($copy))->not->toContain('evil');
});

it('cleans captions but keeps line breaks; stickers, locations and video notes get no caption (B4-S4)', function (array $payload, ?string $caption) {
    inboxUser();

    inboxSend($payload);

    $copy = inboxCalls('copyMessage')[0]['data'];

    if ($caption === null) {
        expect($copy)->not->toHaveKey('caption')->not->toHaveKey('caption_entities');
    } else {
        expect($copy['caption'])->toBe($caption)->and($copy['caption_entities'])->toBe([]);
    }
})->with([
    'document' => [inboxDocument([], ['caption' => "Hisob\u{202E}faktura\nNo 12\u{2066}"]), "Hisobfaktura\nNo 12"],
    'voice without caption' => [['voice' => ['file_id' => 'x', 'duration' => 1]], ''],
    'sticker' => [['sticker' => ['file_id' => 'x', 'width' => 1, 'height' => 1]], null],
    'location' => [['location' => ['latitude' => 1.0, 'longitude' => 2.0]], null],
    'video note' => [['video_note' => ['file_id' => 'x', 'duration' => 1]], null],
]);

it('sends the header once per 10 minutes with the cleaned name and chat #id only, without buttons (TG-24, B4-S7)', function () {
    config(['app.url' => 'http://poson.test']);
    $user = inboxUser(['name' => "Ali\u{202E} <b>Boss</b>\nVali"]);

    inboxSend(inboxPhoto(['media_group_id' => 'album1']));
    inboxSend(inboxPhoto(['media_group_id' => 'album1']));
    inboxSend(inboxDocument());

    $headers = inboxCalls('sendMessage', INBOX_SALES_CHAT);
    $header = $headers[0]['data'];

    expect($headers)->toHaveCount(1)
        ->and(inboxCalls('copyMessage'))->toHaveCount(3)
        ->and($header['text'])->toBe('<b>'.e(__('telegram.inbox.customer', [], 'uz')).'</b> Ali &lt;b&gt;Boss&lt;/b&gt; Vali · '.e(__('telegram.inbox.chat', ['id' => $user->id], 'uz')))
        ->and($header['parse_mode'])->toBe('HTML')
        ->and($header)->not->toHaveKey('reply_markup')
        ->and($header['text'])->not->toContain((string) INBOX_USER)->not->toContain('ali_secret_username')->not->toContain('+998')->not->toContain('Secret Company')->not->toContain('123456789');

    $this->travel(11)->minutes();
    inboxSend(inboxDocument());

    expect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toHaveCount(2)
        ->and($user->fresh()->forward_header_chat_id)->toBe((int) INBOX_SALES_CHAT);
});

it('limits the header name to 64 characters and falls back to the Telegram name', function () {
    $user = inboxUser(['name' => null, 'first_name' => str_repeat('Ж', 80), 'last_name' => 'X']);

    expect(InboxMessage::displayName($user))->toBe(str_repeat('Ж', 64));
});

it('never repeats the header when the copy is retried after a server error', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    inboxUser();
    $copies = 0;
    inboxApi(['copyMessage' => function () use (&$copies) {
        return ++$copies === 1 ? 502 : ['message_id' => 905];
    }]);

    inboxSend(inboxPhoto());
    $row = inboxLastInbound();
    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending)
        ->and($row->fresh()->forward_error_code)->toBe(502);

    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and($row->fresh()->forwarded_message_id)->toBe(905)
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toHaveCount(1)
        ->and(collect(inboxCalls('copyMessage'))->pluck('data.reply_parameters.message_id')->all())->toBe([900, 900]);
});

it('sends a new header when the target chat changes (B4-S3)', function () {
    $user = inboxUser();

    inboxSend(inboxPhoto());
    telegramBotConfigured(['services.telegram.chats.inbox' => INBOX_CHAT]);
    inboxSend(inboxPhoto());

    expect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toHaveCount(1)
        ->and(inboxCalls('sendMessage', INBOX_CHAT))->toHaveCount(1)
        ->and(collect(inboxCalls('copyMessage'))->pluck('data.chat_id')->all())->toBe([INBOX_SALES_CHAT, INBOX_CHAT])
        ->and($user->fresh()->forward_header_chat_id)->toBe((int) INBOX_CHAT);
});

it('serializes an album per conversation: a held lock releases the job, then one header for all files (B4-S3)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $user = inboxUser();

    inboxSend(inboxPhoto(['media_group_id' => 'a1']));
    inboxSend(inboxPhoto(['media_group_id' => 'a1']));
    $rows = TelegramMessage::query()->where('direction', TelegramDirection::In)->orderBy('id')->get();

    $first = new ForwardTelegramMedia($rows[0]->id, $user->id);
    $lock = Cache::lock((new WithoutOverlapping(ForwardTelegramMedia::lockKey($user->id)))->getLockKey($first), 60);
    expect($lock->get())->toBeTrue();

    inboxRunForward($rows[0])->assertReleased();
    expect(inboxCalls('copyMessage'))->toBe([])
        ->and($rows[0]->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending);

    $lock->release();

    foreach ($rows as $row) {
        inboxRunForward($row);
    }

    expect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toHaveCount(1)
        ->and(inboxCalls('copyMessage'))->toHaveCount(2);
});

it('claims before forwarding: a second run or a row already sending never copies again (TG-25)', function () {
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();

    dispatch_sync(new ForwardTelegramMedia($row->id, $row->telegram_user_id));

    $row->forceFill(['forward_status' => TelegramForwardStatus::Sending])->save();
    dispatch_sync(new ForwardTelegramMedia($row->id, $row->telegram_user_id));

    expect(inboxCalls('copyMessage'))->toHaveCount(1);
});

it('skips banned users and a disabled bot without changing the status', function (Closure $setup) {
    Bus::fake([ForwardTelegramMedia::class]);
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();

    $setup($row);
    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending)
        ->and(inboxCalls('copyMessage'))->toBe([]);
})->with([
    'kill switch off' => [fn () => inboxBotOn(false)],
    'banned' => [fn (TelegramMessage $row) => $row->telegramUser->forceFill(['banned_at' => now()])->save()],
]);

/* ---------------------------------------------------------- target chat */

it('resolves one target: inbox chat first, else sales; only numeric group ids (B4-S1)', function (array $chats, ?string $expected) {
    telegramBotConfigured($chats);
    inboxUser();

    inboxSend(inboxPhoto());

    $row = inboxLastInbound();

    if ($expected === null) {
        expect($row->forward_status)->toBe(TelegramForwardStatus::NotConfigured)
            ->and(inboxCalls('copyMessage'))->toBe([])
            ->and(inboxCalls('getChat'))->toBe([]);
    } else {
        expect($row->forward_status)->toBe(TelegramForwardStatus::Forwarded)
            ->and(inboxCalls('copyMessage')[0]['data']['chat_id'])->toBe($expected);
    }
})->with([
    'inbox wins' => [['services.telegram.chats.inbox' => INBOX_CHAT], INBOX_CHAT],
    'fallback to sales' => [['services.telegram.chats.inbox' => null], INBOX_SALES_CHAT],
    '@channel inbox (no silent fallback)' => [['services.telegram.chats.inbox' => '@poson_files'], null],
    '@channel sales' => [['services.telegram.chats.sales' => '@poson_sales'], null],
    'positive user id' => [['services.telegram.chats.sales' => '123456789'], null],
    'leading zero' => [['services.telegram.chats.sales' => '-0100111'], null],
    'minus zero' => [['services.telegram.chats.sales' => '-0'], null],
    'no chat' => [['services.telegram.chats.sales' => null], null],
    'no token' => [['services.telegram.bot_token' => null], null],
]);

it('refuses public groups, channels and unknown chats after getChat (TG-23)', function (mixed $chat) {
    inboxApi(['getChat' => $chat]);
    inboxUser();

    inboxSend(inboxPhoto());

    expect(inboxLastInbound()->forward_status)->toBe(TelegramForwardStatus::NotConfigured)
        ->and(inboxCalls('copyMessage'))->toBe([])
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([])
        ->and(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(1);
})->with([
    'public supergroup' => [['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'poson_public']],
    'channel' => [['id' => -1001111111111, 'type' => 'channel']],
    'private chat' => [['id' => 1, 'type' => 'private']],
    'chat not found' => [400],
    'bot not a member' => [403],
]);

it('caches the getChat check for 10 minutes and checks again after a chat id or the token is saved (B4-S2)', function () {
    inboxUser();

    inboxSend(inboxPhoto());
    inboxSend(inboxPhoto());

    expect(inboxCalls('getChat'))->toHaveCount(1);

    app(SaveIntegrationSettings::class)->handle(['telegram.chats.service' => '-1003333333333'], null, null);
    inboxSend(inboxPhoto());

    expect(inboxCalls('getChat'))->toHaveCount(2);

    app(SetSecret::class)->handle('telegram.bot_token', TG_BOT_TOKEN, null, null);
    inboxSend(inboxPhoto());

    expect(inboxCalls('getChat'))->toHaveCount(3);

    $this->travel(11)->minutes();
    inboxSend(inboxPhoto());

    expect(inboxCalls('getChat'))->toHaveCount(4);
});

it('never caches a transient getChat failure and never marks it not configured (B4-S2)', function (int $status) {
    Bus::fake([ForwardTelegramMedia::class]);
    $checks = 0;
    inboxApi(['getChat' => function (Request $request) use (&$checks, $status) {
        return ++$checks === 1 ? $status : ['id' => -1, 'type' => 'supergroup'];
    }]);
    inboxUser();

    inboxSend(inboxPhoto());
    $row = inboxLastInbound();
    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending);

    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and(inboxCalls('getChat'))->toHaveCount(2);
})->with([502, 429]);

it('drops the cached check after a 400 / 403 from the target', function (int $status) {
    inboxApi(['copyMessage' => $status]);
    inboxUser();

    inboxSend(inboxPhoto());
    inboxApi();
    inboxSend(inboxPhoto());

    // Without the 400 / 403 the cached check (10 min) would have been reused.
    expect(inboxCalls('getChat'))->toHaveCount(2)
        ->and(inboxLastInbound()->forward_status)->toBe(TelegramForwardStatus::Forwarded);
})->with([400, 403]);

/* -------------------------------------------------------- failure modes */

it('marks every 400 failed with its code, no retry (B4-S8)', function () {
    inboxApi(['copyMessage' => 400]);
    inboxUser();

    inboxSend(inboxPhoto());

    $row = inboxLastInbound();

    expect($row->forward_status)->toBe(TelegramForwardStatus::Failed)
        ->and($row->forward_error_code)->toBe(400)
        ->and(inboxCalls('copyMessage'))->toHaveCount(1)
        ->and(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([]);
});

it('alerts the management chat once when the bot lost the inbox chat (403)', function () {
    inboxApi(['copyMessage' => 403]);
    inboxUser();

    inboxSend(inboxPhoto());
    inboxSend(inboxDocument());

    $alerts = inboxCalls('sendMessage', TG_MANAGEMENT_CHAT);

    expect(TelegramMessage::query()->where('forward_status', TelegramForwardStatus::Failed)->where('forward_error_code', 403)->count())->toBe(2)
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.events.telegram_forward', [], 'uz')))
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.lost_target', [], 'uz')))
        ->and($alerts[0]['data']['text'])->not->toContain(INBOX_SALES_CHAT);
});

it('releases a 429 back to pending for retry_after without using a retry (B4-S8)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    inboxApi(['copyMessage' => 429]);
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();

    $job = (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    $job->assertReleased(delay: 7);
    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending)
        ->and($row->fresh()->forward_error_code)->toBe(429)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(5)->getTimestamp())
        ->and($job->maxExceptions)->toBe(5);
});

it('fails the file when retries are used up', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();
    $row->forceFill(['forward_error_code' => 502])->save();

    (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->failed(new TelegramException(504));

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Failed)
        ->and($row->fresh()->forward_error_code)->toBe(504);
});

it('logs ids, kind, status and code only (B4-S9)', function () {
    $logs = inboxCaptureLogs();
    inboxApi(['copyMessage' => 400]);
    inboxUser(['name' => 'Secret Person']);

    inboxSend(inboxDocument(['file_name' => 'secret-file.pdf'], ['caption' => 'secret caption']));

    $logged = implode("\n", $logs());

    expect($logged)->toContain('Telegram media forward failed')
        ->and($logged)->toContain('"code":"400"')
        ->and($logged)->not->toContain('secret')->not->toContain('Secret')
        ->and($logged)->not->toContain('ali_secret_username')
        ->and($logged)->not->toContain((string) INBOX_USER)
        ->and($logged)->not->toContain(INBOX_SALES_CHAT)
        ->and($logged)->not->toContain(TG_BOT_TOKEN)
        ->and($logged)->not->toContain('description');
});

/* ------------------------------------------------------------------ caps */

it('limits forwards to 30 an hour per conversation (TG-26)', function () {
    $user = inboxUser();
    RateLimiter::clear(ForwardTelegramMedia::conversationKey($user->id));

    for ($i = 0; $i < ForwardTelegramMedia::CONVERSATION_PER_HOUR; $i++) {
        RateLimiter::hit(ForwardTelegramMedia::conversationKey($user->id), 3600);
    }

    inboxSend(inboxPhoto());
    inboxSend(inboxPhoto());

    expect(inboxLastInbound()->forward_status)->toBe(TelegramForwardStatus::Limited)
        ->and(inboxCalls('copyMessage'))->toBe([])
        ->and(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(1);
});

it('limits forwards to 300 a day overall with one management notice a day (TG-26)', function () {
    inboxUser();

    for ($i = 0; $i < ForwardTelegramMedia::DAILY_TOTAL; $i++) {
        RateLimiter::hit(ForwardTelegramMedia::DAILY_KEY, 86400);
    }

    inboxSend(inboxPhoto());
    inboxSend(inboxDocument());

    $alerts = inboxCalls('sendMessage', TG_MANAGEMENT_CHAT);

    expect(TelegramMessage::query()->where('forward_status', TelegramForwardStatus::Limited)->count())->toBe(2)
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.daily_limit', [], 'uz')));
});

/* ----------------------------------------------------------- RetryForward */

it('lets staff who can view the conversation retry a failed file once a minute, under the same caps', function () {
    $manager = staffUser(Role::SalesManager);
    inboxApi(['copyMessage' => 400]);
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();
    inboxApi();

    expect(app(RetryForward::class)->handle($manager, $row->fresh()))->toBeTrue()
        ->and($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded);

    $row->refresh()->forceFill(['forward_status' => TelegramForwardStatus::Failed])->save();

    expect(app(RetryForward::class)->handle($manager, $row->fresh()))->toBeFalse();

    $this->travel(61)->seconds();

    for ($i = 0; $i < ForwardTelegramMedia::CONVERSATION_PER_HOUR; $i++) {
        RateLimiter::hit(ForwardTelegramMedia::conversationKey($row->telegram_user_id), 3600);
    }

    expect(app(RetryForward::class)->handle($manager, $row->fresh()))->toBeTrue()
        ->and($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Limited);
});

it('refuses a retry on another manager\'s conversation (policy view)', function () {
    $manager = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $user = inboxUser(['manager_id' => $other->id]);
    $row = TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => 50, 'forward_status' => TelegramForwardStatus::Failed,
    ]);

    expect(fn () => app(RetryForward::class)->handle($manager, $row))->toThrow(AuthorizationException::class);
    expect(fn () => app(RetryForward::class)->handle(staffUser(Role::ServiceEngineer), $row))->toThrow(AuthorizationException::class);
});

it('reclaims only stuck sending rows and never forwarded or fresh ones (B4-S11c)', function (TelegramForwardStatus $status, int $minutesAgo, bool $queued) {
    Bus::fake([ForwardTelegramMedia::class]);
    $admin = staffUser(Role::Admin);
    $user = inboxUser();
    $row = TelegramMessage::factory()->media(TelegramMessageKind::Document)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => 51, 'forward_status' => $status,
    ]);
    TelegramMessage::query()->whereKey($row->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

    expect(app(RetryForward::class)->handle($admin, $row->fresh()))->toBe($queued);

    $queued ? Bus::assertDispatched(ForwardTelegramMedia::class) : Bus::assertNotDispatched(ForwardTelegramMedia::class);
})->with([
    'stuck sending' => [TelegramForwardStatus::Sending, 11, true],
    'fresh sending' => [TelegramForwardStatus::Sending, 2, false],
    'forwarded' => [TelegramForwardStatus::Forwarded, 60, false],
    'pending that never ran (L1)' => [TelegramForwardStatus::Pending, 11, true],
    'fresh pending' => [TelegramForwardStatus::Pending, 2, false],
    'not configured' => [TelegramForwardStatus::NotConfigured, 0, true],
    'limited' => [TelegramForwardStatus::Limited, 0, true],
]);

it('finds stuck deliveries and forwards with the stuck scope', function () {
    $user = inboxUser();
    $old = now()->subMinutes(11);
    $stuckSend = TelegramMessage::factory()->outbound()->create(['telegram_user_id' => $user->id, 'status' => TelegramMessageStatus::Sending]);
    $stuckForward = TelegramMessage::factory()->media()->create(['telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => 52, 'forward_status' => TelegramForwardStatus::Sending]);
    $fresh = TelegramMessage::factory()->outbound()->create(['telegram_user_id' => $user->id, 'status' => TelegramMessageStatus::Sending]);
    $done = TelegramMessage::factory()->outbound()->create(['telegram_user_id' => $user->id, 'status' => TelegramMessageStatus::Sent]);
    TelegramMessage::query()->whereKey([$stuckSend->id, $stuckForward->id, $done->id])->update(['updated_at' => $old]);

    expect(TelegramMessage::query()->stuck()->pluck('id')->sort()->values()->all())->toBe([$stuckSend->id, $stuckForward->id])
        ->and($fresh->id)->not->toBeIn(TelegramMessage::query()->stuck()->pluck('id')->all());
});

/* ---------------------------------------------------------------- B3 lows */

it('skips callback clean-up when a prompt is retried (B4-S11b)', function () {
    $user = inboxUser();

    $retry = new class($user->id, ['menu.main'], BotKeyboards::MENU, 'cbq-1', 77) extends SendBotPrompt
    {
        public function attempts(): int
        {
            return 2;
        }
    };
    $retry->handle(app(TelegramClient::class));

    expect(inboxCalls('answerCallbackQuery'))->toBe([])
        ->and(inboxCalls('editMessageReplyMarkup'))->toBe([])
        ->and(inboxCalls('sendMessage', (string) INBOX_USER))->toHaveCount(1);

    (new SendBotPrompt($user->id, ['menu.main'], BotKeyboards::MENU, 'cbq-2', 78))->handle(app(TelegramClient::class));

    expect(inboxCalls('answerCallbackQuery'))->toHaveCount(1)
        ->and(inboxCalls('editMessageReplyMarkup'))->toHaveCount(1);
});

/* -------------------------------------------------------- B4 fix batch */

it('counts each file once against the caps: 29 files, one gets 429 twice → 0 limited, counter 29 (M1)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $user = inboxUser();

    for ($i = 0; $i < 29; $i++) {
        // Stay under the bot's own 20 updates / minute per user.
        if ($i === 15) {
            $this->travel(61)->seconds();
        }

        inboxSend(inboxPhoto());
    }

    $rows = TelegramMessage::query()->where('direction', TelegramDirection::In)->orderBy('id')->get();
    $unlucky = $rows[4];
    $tries = 0;
    inboxApi(['copyMessage' => function (Request $request) use ($unlucky, &$tries) {
        if ($request->data()['message_id'] === $unlucky->telegram_message_id && ++$tries <= 2) {
            return 429;
        }

        return ['message_id' => 1000 + $request->data()['message_id']];
    }]);

    foreach ($rows as $row) {
        inboxRunForward($row);
    }

    inboxRunForward($unlucky)->assertReleased();
    inboxRunForward($unlucky);

    expect(TelegramMessage::query()->where('forward_status', TelegramForwardStatus::Limited)->count())->toBe(0)
        ->and(TelegramMessage::query()->where('forward_status', TelegramForwardStatus::Forwarded)->count())->toBe(29)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($user->id)))->toBe(29)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(29);
});

it('does not re-count a file retried after a server error, but a staff retry counts again (M1)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $admin = staffUser(Role::Admin);
    $user = inboxUser();
    inboxApi(['copyMessage' => 502]);
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();

    inboxRunForward($row);
    inboxRunForward($row);

    expect(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($user->id)))->toBe(1);

    $row->refresh()->forceFill(['forward_status' => TelegramForwardStatus::Failed])->save();
    app(RetryForward::class)->handle($admin, $row->fresh());

    expect(Cache::has(ForwardTelegramMedia::capDecisionKey($row->id)))->toBeFalse();

    inboxRunForward($row);

    expect(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($user->id)))->toBe(2);
});

it('keeps a limited decision for the file\'s automatic retries (M1)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $user = inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();
    Cache::put(ForwardTelegramMedia::capDecisionKey($row->id), 'daily_limit', 3600);

    inboxRunForward($row);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Limited)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::conversationKey($user->id)))->toBe(0);
});

it('fails a run killed by the worker timeout instead of retrying it (L2)', function () {
    $job = new ForwardTelegramMedia(1, 1);

    expect($job->timeout)->toBe(60)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('puts the claim back to pending and rethrows on a non-Telegram error', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    inboxUser();
    inboxSend(inboxPhoto());
    $row = inboxLastInbound();

    $broken = new class extends TelegramClient
    {
        public function getChat(string $chatId): array
        {
            throw new RuntimeException('database gone');
        }
    };

    expect(fn () => (new ForwardTelegramMedia($row->id, $row->telegram_user_id))->handle($broken))->toThrow(RuntimeException::class);
    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Pending);
});

it('takes the inbox counters from the locked row, not from an older model', function () {
    $user = inboxUser(['unread_count' => 0, 'last_alert_at' => null]);
    TelegramUser::query()->whereKey($user->id)->update(['unread_count' => 5, 'last_alert_at' => now()->subMinute()]);

    Event::fake([TelegramInboxMessage::class]);
    app(StoreInboundMessage::class)->handle($user, UpdateParser::fromArray(telegramPrivateUpdate(inboxNextUpdateId(), ['text' => 'hi'])));

    expect($user->fresh()->unread_count)->toBe(6);
    Event::assertNotDispatched(TelegramInboxMessage::class);
});

it('uses the per-minute retry limit only after the policy and status checks pass', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $admin = staffUser(Role::Admin);
    $user = inboxUser();
    $row = TelegramMessage::factory()->media(TelegramMessageKind::Photo)->create([
        'telegram_user_id' => $user->id, 'direction' => TelegramDirection::In, 'telegram_message_id' => 60, 'forward_status' => TelegramForwardStatus::Forwarded,
    ]);

    expect(app(RetryForward::class)->handle($admin, $row))->toBeFalse();

    $row->forceFill(['forward_status' => TelegramForwardStatus::Failed])->save();

    expect(app(RetryForward::class)->handle($admin, $row->fresh()))->toBeTrue()
        ->and(app(RetryForward::class)->handle($admin, $row->fresh()))->toBeFalse();
});

it('labels the checklist chat item as an ID format check, not a verified group', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(__('admin.telegram_bot.checks.chat', [], $locale))->not->toBe('admin.telegram_bot.checks.chat');
    }

    expect(__('admin.telegram_bot.checks.chat', [], 'en'))->toContain('ID format OK')->toContain('not verified');
});

/* ----------------------------------------------- M3: text alert target */

it('raises one deduped refused_target monitoring alert for a refused text target, keeps the message and sends nothing to it (M3)', function () {
    inboxApi(['getChat' => ['id' => -1, 'type' => 'supergroup', 'username' => 'open_group']]);
    inboxUser();

    inboxSend(['text' => 'first secret text']);
    TelegramUser::query()->update(['last_alert_at' => null]);
    inboxSend(['text' => 'second secret text']);

    $alerts = inboxCalls('sendMessage', TG_MANAGEMENT_CHAT);

    expect(TelegramMessage::query()->where('direction', TelegramDirection::In)->where('kind', TelegramMessageKind::Text)->count())->toBe(2)
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([])
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.refused_target', [], 'uz')))
        ->and($alerts[0]['data']['text'])->not->toContain('secret text');
});

it('retries a transient getChat failure for the text alert: nothing sent or cached, log has only id and code (M3)', function (int $status) {
    $logs = inboxCaptureLogs();
    inboxUser(['name' => 'Private Name']);
    inboxApi(['getChat' => $status]);

    Event::fake([TelegramInboxMessage::class]);
    inboxSend(['text' => 'secret text +998901234567']);
    $message = inboxLastInbound();
    $listener = app(SendTelegramInboxAlert::class);

    expect(fn () => $listener->handle(new TelegramInboxMessage($message->id)))->toThrow(TelegramException::class);

    $log = implode("\n", $logs());

    expect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([])
        ->and(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and($log)->toContain('"telegram_message_id":'.$message->id)->toContain((string) $status)
        ->and($log)->not->toContain('secret text')->not->toContain('Private Name')->not->toContain('998901234567');

    // Nothing was cached: the next try asks Telegram again and, now healthy, sends the alert.
    inboxApi(['getChat' => null]);
    $listener->handle(new TelegramInboxMessage($message->id));

    expect(collect(inboxCalls('sendMessage', INBOX_SALES_CHAT))->filter(fn ($call) => str_contains($call['data']['text'], 'secret text')))->toHaveCount(1);
})->with([502, 429]);

it('configures the alert listener to retry and gives the alert slot back when retries run out (M3)', function () {
    inboxUser();
    Event::fake([TelegramInboxMessage::class]);
    inboxSend(['text' => 'hello']);
    $message = inboxLastInbound();
    $listener = app(SendTelegramInboxAlert::class);

    expect($listener->tries)->toBe(4)
        ->and($listener->backoff())->toBe([30, 120, 300])
        ->and(TelegramUser::query()->sole()->last_alert_at)->not->toBeNull();

    $listener->failed(new TelegramInboxMessage($message->id), new RuntimeException('x'));

    expect(TelegramUser::query()->sole()->last_alert_at)->toBeNull();
});

it('refuses a configured @username inbox target loudly: one management alert, no getChat, nothing sent to it, media not_configured (B4-S2)', function () {
    telegramBotConfigured(['services.telegram.chats.sales' => '@poson_sales']);
    inboxUser();

    inboxSend(['text' => 'first secret text']);
    TelegramUser::query()->update(['last_alert_at' => null]);
    inboxSend(['text' => 'second secret text']);
    inboxSend(inboxPhoto());

    $alerts = inboxCalls('sendMessage', TG_MANAGEMENT_CHAT);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['data']['text'])->toContain(e(__('telegram.monitoring.forward_reasons.refused_target', [], 'uz')))
        ->and(inboxCalls('getChat'))->toBe([])
        ->and(inboxCalls('sendMessage', '@poson_sales'))->toBe([])
        ->and(inboxCalls('copyMessage'))->toBe([])
        ->and(TelegramMessage::query()->where('kind', TelegramMessageKind::Photo)->sole()->forward_status)->toBe(TelegramForwardStatus::NotConfigured);
});

it('stays silent when no inbox or sales chat is configured', function () {
    telegramBotConfigured(['services.telegram.chats.sales' => '']);
    inboxUser();

    inboxSend(['text' => 'hello']);

    expect(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toBe([])
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([])
        ->and(inboxCalls('getChat'))->toBe([]);
});

it('keeps the alert slot of a newer alert when the retries of an older message run out', function () {
    inboxUser();
    Event::fake([TelegramInboxMessage::class]);
    inboxSend(['text' => 'hello']);
    $message = inboxLastInbound();
    $newer = now()->addMinutes(11)->startOfSecond();
    TelegramUser::query()->update(['last_alert_at' => $newer]);

    app(SendTelegramInboxAlert::class)->failed(new TelegramInboxMessage($message->id), new RuntimeException('x'));

    expect(TelegramUser::query()->sole()->last_alert_at->equalTo($newer))->toBeTrue();
});

it('sends nothing on a retry when the bot switch or telegram.notify_inbox was turned off meanwhile', function (string $off) {
    inboxUser();
    Event::fake([TelegramInboxMessage::class]);
    inboxSend(['text' => 'secret text']);
    $message = inboxLastInbound();
    $listener = app(SendTelegramInboxAlert::class);

    expect((new TelegramInboxMessage($message->id))->telegramMessage('uz'))->not->toBeNull();

    if ($off === 'switch') {
        inboxBotOn(false);
    } else {
        freshSecrets(['services.telegram.notify_inbox' => 'false']);
    }

    $listener->handle(new TelegramInboxMessage($message->id));

    expect((new TelegramInboxMessage($message->id))->telegramMessage('uz'))->toBeNull()
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([]);
})->with(['switch', 'notify_inbox']);

it('shortens the dedupe window when the management alert fails: one attempt per 5 minutes, then one delivery once Telegram is healthy', function () {
    Exceptions::fake();
    inboxApi(['sendMessage' => 500]);

    foreach (range(1, 5) as $ignored) {
        ForwardAlerts::once('refused_target', 3600);
    }

    expect(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(1);

    $this->travel(6)->minutes();
    inboxApi(['sendMessage' => null]);
    ForwardAlerts::once('refused_target', 3600);
    ForwardAlerts::once('refused_target', 3600);
    ForwardAlerts::once('refused_target', 3600);

    expect(inboxCalls('sendMessage', TG_MANAGEMENT_CHAT))->toHaveCount(2);
});

it('lets SendTelegramAlerts queue order and other alerts but never the inbox alert', function () {
    $listener = new SendTelegramAlerts;

    expect($listener->shouldQueue(new TelegramInboxMessage(1)))->toBeFalse()
        ->and($listener->shouldQueue(new OrderPlaced(Order::factory()->create())))->toBeTrue()
        ->and($listener->shouldQueue(Mockery::mock(TelegramAlert::class)))->toBeTrue();
});

it('has a monitoring job label in every locale for each retryable class', function () {
    foreach (config('monitoring.retry_classes') as $class) {
        $key = 'monitoring.job_labels.'.Str::snake(class_basename($class));

        foreach (['uz', 'ru', 'en'] as $locale) {
            expect(__($key, [], $locale))->not->toBe($key, "{$class} lacks a {$locale} label");
        }
    }
});

it('queues the inbox alert listener with tries 4 and backoff 30/120/300 (T1)', function () {
    Queue::fake();

    TelegramInboxMessage::dispatch(1);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendTelegramInboxAlert::class
        && $job->tries === 4
        && $job->backoff === [30, 120, 300]);
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendTelegramAlerts::class);
    expect(config('monitoring.retry_classes'))->toContain(SendTelegramInboxAlert::class);
});

it('keeps a transient getChat failure out of the webhook: message stored, slot released, nothing sent (T2)', function () {
    Exceptions::fake();
    inboxApi(['getChat' => 502]);
    inboxUser();

    $update = telegramPrivateUpdate(inboxNextUpdateId(), ['text' => 'secret text'], INBOX_USER);
    postTelegramUpdate($update)->assertOk();

    expect(TelegramMessage::query()->where('kind', TelegramMessageKind::Text)->where('direction', TelegramDirection::In)->count())->toBe(1)
        ->and(TelegramUser::query()->sole()->last_alert_at)->toBeNull()
        ->and(inboxCalls('sendMessage', INBOX_SALES_CHAT))->toBe([]);
});

it('does not hand other alert events to the inbox listener and leaves their listener unretried (T3)', function () {
    Queue::fake();

    Event::dispatch(new OrderPlaced(Order::factory()->create()));

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendTelegramAlerts::class && $job->tries === null);
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendTelegramInboxAlert::class);

    Queue::fake();
    TelegramInboxMessage::dispatch(1);

    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendTelegramAlerts::class);
    expect(property_exists(SendTelegramAlerts::class, 'tries'))->toBeFalse()
        ->and(method_exists(SendTelegramAlerts::class, 'backoff'))->toBeFalse()
        ->and(method_exists(SendTelegramAlerts::class, 'failed'))->toBeFalse();
});
