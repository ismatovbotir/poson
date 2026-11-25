<?php

use App\Actions\Office\HandleBusinessUpdate;
use App\Actions\Office\UpsertBusinessConnection;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\BusinessAuthor;
use App\Enums\BusinessChatKind;
use App\Enums\SettingsArea;
use App\Jobs\FetchBusinessConnection;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Models\BusinessMessage;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Support\Office\OfficeLimits;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BusinessUpdate;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 §3: the Telegram Business ingest (VO-1, VO-2, VO-3, VO-4, VO-13).
*/

beforeEach(function () {
    RateLimiter::clear(OfficeLimits::NEW_CHATS_MINUTE);
    RateLimiter::clear(OfficeLimits::NEW_CHATS_DAY);
    RateLimiter::clear(OfficeLimits::FETCHES_HOUR);
});

function officeHandle(array $payload): string
{
    return app(HandleTelegramUpdate::class)->handle($payload);
}

it('parses the four business update types into a BusinessUpdate, never an UpdateParser', function (string $fixture, string $type) {
    $update = UpdateParser::fromArray(officeFixture($fixture, 1));

    expect($update)->toBeInstanceOf(BusinessUpdate::class)
        ->and($update->type)->toBe($type);
})->with([
    ['connection_enabled', BusinessUpdate::TYPE_CONNECTION],
    ['message_client', BusinessUpdate::TYPE_MESSAGE],
    ['message_edited', BusinessUpdate::TYPE_EDITED],
    ['messages_deleted', BusinessUpdate::TYPE_DELETED],
]);

it('stores a connection with whitelisted rights only and audits connect / disconnect without ids (VO-13)', function () {
    officeSettings(['owner_telegram_ids' => [OFFICE_OWNER_ID]]);

    expect(officeHandle(officeFixture('connection_enabled', 1)))->toBe(HandleTelegramUpdate::BUSINESS);

    $connection = BusinessConnection::query()->sole();
    expect($connection->is_enabled)->toBeTrue()
        ->and($connection->is_allowed)->toBeTrue()
        ->and($connection->can_reply)->toBeTrue()
        ->and($connection->rights)->toBe(['can_reply' => true, 'can_read_messages' => true]);

    officeHandle(officeFixture('connection_disabled', 2));

    expect($connection->fresh()->is_enabled)->toBeFalse()
        ->and($connection->fresh()->disabled_at)->not->toBeNull();

    $audits = SettingsAudit::query()->where('area', SettingsArea::Office->value)->pluck('key')->all();
    expect($audits)->toBe(['office.connection', 'office.connection'])
        ->and(implode(' ', $audits))->not->toContain((string) OFFICE_OWNER_ID);
});

it('refuses a connection id that is not a short token (VO-13)', function () {
    officeSettings(['owner_telegram_ids' => [OFFICE_OWNER_ID]]);

    officeHandle(officeFixture('connection_enabled', 1, ['business_connection' => ['id' => 'bad id/../x']]));

    expect(BusinessConnection::query()->count())->toBe(0);
});

it('stores nothing from a stranger connection: only the dedupe row and one disallowed connection row (VO-1)', function () {
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]);

    officeHandle(officeFixture('connection_stranger', 1));

    foreach ([2, 3, 4] as $updateId) {
        officeHandle(officeFixture('message_client', $updateId, ['business_message' => ['business_connection_id' => 'fake-conn-0099', 'message_id' => $updateId]]));
    }

    expect(BusinessConnection::query()->sole()->is_allowed)->toBeFalse()
        ->and(BusinessChat::query()->count())->toBe(0)
        ->and(BusinessMessage::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->count())->toBe(4);
});

it('stores nothing while the allow-list is empty or ingest is off (VO-1)', function (array $settings) {
    officeSettings($settings);
    BusinessConnection::factory()->create(['connection_id' => OFFICE_CONNECTION_ID, 'owner_telegram_id' => OFFICE_OWNER_ID]);

    officeHandle(officeFixture('message_client', 1));

    expect(BusinessChat::query()->count())->toBe(0);
})->with([
    'empty allow-list' => [['ingest_enabled' => true, 'owner_telegram_ids' => []]],
    'ingest off' => [['ingest_enabled' => false, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]],
]);

it('stops the ingest when the owner is removed from the allow-list, even if the stored flag is stale (VO-1)', function () {
    officeIngestReady();
    officeHandle(officeFixture('message_client', 1));
    expect(BusinessChat::query()->count())->toBe(1);

    // The flag stays true (no recompute), the live allow-list decides.
    officeSettings(['owner_telegram_ids' => [9990000002]]);
    officeHandle(officeFixture('message_client', 2, ['business_message' => ['message_id' => 201, 'chat' => ['id' => 9990010002], 'from' => ['id' => 9990010002]]]));

    expect(BusinessChat::query()->count())->toBe(1);
});

it('drops a message whose sender is neither the owner nor the chat, and business bot echoes (VO-1)', function (string $fixture) {
    officeIngestReady();

    officeHandle(officeFixture($fixture, 1));

    expect(BusinessChat::query()->count())->toBe(0);
})->with(['message_third_party', 'message_bot_echo']);

it('creates an unmarked stub with counters only: no text, media, phone or message id (VO-4)', function () {
    officeIngestReady();

    officeHandle(officeFixture('message_client', 1));
    officeHandle(officeFixture('message_contact', 2));
    officeHandle(officeFixture('message_document', 3));
    officeHandle(officeFixture('message_owner', 4));

    $chat = BusinessChat::query()->sole();

    expect($chat->kind)->toBe(BusinessChatKind::Unmarked)
        ->and($chat->first_name)->toBe('Aziza')
        ->and($chat->username)->toBe('fake_client')
        ->and($chat->unread_count)->toBe(3)
        ->and($chat->phone)->toBeNull()
        ->and($chat->last_owner_at)->not->toBeNull()
        ->and($chat->analysis_due_at)->toBeNull()
        ->and(BusinessMessage::query()->count())->toBe(0);
});

it('creates nothing for an edit or a delete of a message that was never stored (VO-4)', function () {
    officeIngestReady();

    officeHandle(officeFixture('message_edited', 1));
    officeHandle(officeFixture('messages_deleted', 2));

    expect(BusinessChat::query()->count())->toBe(0);

    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => BusinessConnection::query()->value('id'), 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    officeHandle(officeFixture('message_edited', 3));

    expect($chat->messages()->count())->toBe(0);
});

it('stores nothing from employee and ignored chats', function (string $state) {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->{$state}()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);

    officeHandle(officeFixture('message_client', 1));
    officeHandle(officeFixture('message_contact', 2));

    expect($chat->messages()->count())->toBe(0)
        ->and($chat->fresh()->phone)->toBeNull();
})->with(['employee', 'ignored']);

it('stores client chat messages, edits them, takes the client’s own shared contact and schedules an analysis', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);

    officeHandle(officeFixture('message_client', 1));
    officeHandle(officeFixture('message_owner', 2));
    officeHandle(officeFixture('message_contact', 3));
    officeHandle(officeFixture('message_document', 4));
    officeHandle(officeFixture('message_edited', 5));

    $messages = $chat->messages()->orderBy('telegram_message_id')->get();
    $chat->refresh();

    expect($messages)->toHaveCount(4)
        ->and($messages[0]->author)->toBe(BusinessAuthor::Client)
        ->and($messages[0]->text)->toContain('40 mm')
        ->and($messages[0]->edited_at)->not->toBeNull()
        ->and($messages[1]->author)->toBe(BusinessAuthor::Owner)
        ->and($messages[3]->media)->toBe(['file_size' => 20480, 'mime_type' => 'application/pdf', 'file_name' => 'rekvizitlar.pdf'])
        ->and($chat->phone)->toBe('+998900000017')
        ->and($chat->analysis_due_at)->not->toBeNull();
});

it('never takes a forwarded contact (user_id is not the chat) as the client phone', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);

    officeHandle(officeFixture('message_contact', 1, ['business_message' => ['contact' => ['user_id' => 9990010999]]]));

    expect($chat->fresh()->phone)->toBeNull();
});

it('nulls deleted messages and the analysis texts that included them, scoped to the connection (VO-13, VO-18)', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    officeHandle(officeFixture('message_client', 1));
    $message = $chat->messages()->sole();
    $analysis = BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'up_to_message_id' => $message->id, 'draft_reply' => 'Draft']);

    // Another connection deleting the same message id in a chat with the same Telegram id changes nothing.
    $other = BusinessConnection::factory()->create(['connection_id' => 'fake-conn-0002', 'owner_telegram_id' => OFFICE_OWNER_ID]);
    BusinessChat::factory()->client()->create(['business_connection_id' => $other->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    officeHandle(officeFixture('messages_deleted', 2, ['deleted_business_messages' => ['business_connection_id' => 'fake-conn-0002']]));

    expect($message->fresh()->text)->not->toBeNull();

    officeHandle(officeFixture('messages_deleted', 3));

    expect($message->fresh()->text)->toBeNull()
        ->and($message->fresh()->deleted_at)->not->toBeNull()
        ->and($analysis->fresh()->summary)->toBeNull()
        ->and($analysis->fresh()->next_step)->toBeNull()
        ->and($analysis->fresh()->draft_reply)->toBeNull();
});

it('limits new chats per day: the 201st new chat gets no row (VO-3)', function () {
    officeIngestReady();
    config(['office.limits.new_chats_per_minute' => 1000]);

    foreach (range(1, 201) as $index) {
        $id = 9990020000 + $index;
        officeHandle(officeFixture('message_client', $index, ['business_message' => ['chat' => ['id' => $id], 'from' => ['id' => $id]]]));
    }

    expect(BusinessChat::query()->count())->toBe(200);
});

it('limits new chats per minute (VO-3)', function () {
    officeIngestReady();

    foreach (range(1, 31) as $index) {
        $id = 9990030000 + $index;
        officeHandle(officeFixture('message_client', $index, ['business_message' => ['chat' => ['id' => $id], 'from' => ['id' => $id]]]));
    }

    expect(BusinessChat::query()->count())->toBe(30);
});

it('stores at most the daily message cap per client chat but still counts (VO-3)', function () {
    $connection = officeIngestReady();
    config(['office.limits.stored_messages_per_chat_daily' => 3]);
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);

    foreach (range(1, 5) as $index) {
        officeHandle(officeFixture('message_client', $index, ['business_message' => ['message_id' => 300 + $index]]));
    }

    expect($chat->messages()->count())->toBe(3)
        ->and($chat->fresh()->unread_count)->toBe(5);
});

it('fetches an unknown connection once for 50 messages (VO-3)', function () {
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]);
    Bus::fake([FetchBusinessConnection::class]);

    foreach (range(1, 50) as $index) {
        officeHandle(officeFixture('message_client', $index, ['business_message' => ['message_id' => $index]]));
    }

    Bus::assertDispatchedTimes(FetchBusinessConnection::class, 1);
    expect(BusinessChat::query()->count())->toBe(0);
});

it('dedupes a business update by update_id before anything else (VO-3)', function () {
    officeIngestReady();

    expect(officeHandle(officeFixture('message_client', 7)))->toBe(HandleTelegramUpdate::BUSINESS)
        ->and(officeHandle(officeFixture('message_client', 7)))->toBe(HandleTelegramUpdate::DUPLICATE)
        ->and(BusinessChat::query()->sole()->unread_count)->toBe(1);
});

it('never reaches the customer bot: with the bot on and a bot user with the client id, nothing is sent and the user is unchanged (VO-2)', function () {
    telegramBotConfigured();
    RateLimiter::clear('telegram-webhook');
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    Queue::fake();

    $connection = officeIngestReady();
    BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    $botUser = TelegramUser::factory()->create(['telegram_id' => OFFICE_CLIENT_ID, 'unread_count' => 0, 'first_name' => 'Before']);
    $before = $botUser->fresh()->getAttributes();

    foreach (['message_client', 'message_edited', 'messages_deleted', 'message_owner'] as $index => $fixture) {
        postTelegramUpdate(officeFixture($fixture, 100 + $index))->assertOk();
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($botUser->fresh()->getAttributes())->toEqual($before)
        ->and(TelegramUser::query()->count())->toBe(1)
        // The business path did run: the client and owner messages are stored.
        ->and(BusinessMessage::query()->count())->toBe(2);
});

it('pushes only the connection fetch for an unknown connection (VO-2)', function () {
    officeSettings(['ingest_enabled' => true, 'owner_telegram_ids' => [OFFICE_OWNER_ID]]);
    Queue::fake();

    officeHandle(officeFixture('message_client', 1));

    Queue::assertPushed(FetchBusinessConnection::class, 1);
    Queue::assertCount(1);
});

it('drops a plain message that carries a business_connection_id (VO-2)', function () {
    $update = UpdateParser::fromArray(officeFixture('plain_message_with_connection_id', 1));

    expect($update)->toBeInstanceOf(UpdateParser::class)
        ->and($update->type)->toBe(UpdateParser::TYPE_OTHER)
        ->and(officeHandle(officeFixture('plain_message_with_connection_id', 2)))->toBe(HandleTelegramUpdate::IGNORED)
        ->and(TelegramUser::query()->count())->toBe(0);
});

it('fetches and stores the connection with getBusinessConnection only', function () {
    telegramBotConfigured();
    officeSettings(['owner_telegram_ids' => [OFFICE_OWNER_ID]]);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => officeFixture('connection_enabled', 0)['business_connection']])]);

    (new FetchBusinessConnection(OFFICE_CONNECTION_ID))->handle(app(TelegramClient::class), app(UpsertBusinessConnection::class));

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['getBusinessConnection'])
        ->and(BusinessConnection::query()->sole()->is_allowed)->toBeTrue();
});

it('returns a business outcome for each case (direct action)', function () {
    officeSettings(['ingest_enabled' => false]);

    expect(app(HandleBusinessUpdate::class)->handle(UpdateParser::fromArray(officeFixture('message_client', 1))))->toBe(HandleBusinessUpdate::INGEST_OFF);
});
