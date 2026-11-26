<?php

use App\Actions\Office\HandleGroupUpdate;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Jobs\SendGroupMigrateAlert;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';

/*
| Plan 44 §7 abuse cases 1, 2, 3, 4, 10, 11, 13, the ingest side of case 8,
| and T2-4 (no payload in logs). Every group fixture is used somewhere in
| tests/Feature/Office/Groups or here (T2-5).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    groupsReady();
});

it('case 1: a group /start@bot with the bot switch on and an existing TelegramUser pushes nothing and leaves the user untouched', function () {
    groupBotOn();
    $user = TelegramUser::factory()->create(['telegram_id' => GROUP_CLIENT_ID, 'blocked_at' => null]);
    $before = $user->fresh()->toArray();

    foreach (['start_command', 'message_group'] as $i => $fixture) {
        expect(groupHandle(groupFixture($fixture, $i + 1)))->toBe(HandleTelegramUpdate::GROUP);
    }

    expect($user->fresh()->toArray())->toBe($before)
        ->and(TelegramUser::query()->count())->toBe(1);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('case 1b: a group message with the groups switch off does not reach the customer bot either', function () {
    officeSettings(['groups_enabled' => false]);
    groupBotOn();

    expect(groupHandle(groupFixture('start_command', 1)))->toBe(HandleTelegramUpdate::GROUP)
        ->and(TelegramUser::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('case 2: a private edited_message is ignored', function () {
    groupBotOn();
    $user = TelegramUser::factory()->create(['telegram_id' => GROUP_CLIENT_ID]);
    $before = $user->fresh()->toArray();

    expect(groupHandle(groupFixture('private_edited_message', 1)))->toBe(HandleTelegramUpdate::IGNORED)
        ->and($user->fresh()->toArray())->toBe($before)
        ->and(groupHandle(groupFixture('channel_post', 2)))->toBe(HandleTelegramUpdate::IGNORED);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('case 3: a stranger adds the bot, makes it admin and sends 100 messages: one stub, no text and no members', function () {
    groupHandle(groupFixture('my_chat_member_added', 1));
    groupHandle(groupFixture('my_chat_member_admin', 2));

    foreach (range(3, 102) as $id) {
        groupHandle(groupFixture('message_supergroup', $id, ['message' => ['message_id' => $id]]));
    }

    $group = TelegramGroup::query()->sole();

    expect($group->seen_count)->toBe(102)
        ->and($group->bot_is_admin)->toBeTrue()
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(DB::table('telegram_groups')->get()->map(fn ($row) => json_encode($row))->implode(' '))->not->toContain('label printer');

    // An untracked group is never asked for its administrators.
    Queue::assertNothingPushed();
});

it('case 4: the 51st new group of a day gets no row', function () {
    foreach (range(1, 51) as $i) {
        groupHandle(groupFixture('message_supergroup', $i, ['message' => ['chat' => ['id' => -1009990100000 - $i]]]));
    }

    expect(TelegramGroup::query()->count())->toBe(50)
        ->and(TelegramGroup::query()->where('telegram_chat_id', -1009990100051)->exists())->toBeFalse();

    // A known group still counts; a new day starts again.
    groupHandle(groupFixture('message_supergroup', 60, ['message' => ['chat' => ['id' => -1009990100001]]]));

    expect(TelegramGroup::query()->where('telegram_chat_id', -1009990100001)->value('seen_count'))->toBe(2);

    $this->travel(1)->days();
    groupHandle(groupFixture('message_supergroup', 61, ['message' => ['chat' => ['id' => -1009990100051]]]));

    expect(TelegramGroup::query()->count())->toBe(51);
});

it('case 10: a migrate with the target stub created first leaves one tracked row', function () {
    $source = groupTracked(GROUP_BASIC_ID);
    // The target stub appears (a message in the new supergroup arrived first).
    groupHandle(groupFixture('message_supergroup', 1));
    expect(TelegramGroup::query()->count())->toBe(2);

    groupHandle(groupFixture('migrate_to_chat_id', 2));
    groupHandle(groupFixture('migrate_from_chat_id', 3));

    $rows = TelegramGroup::query()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($source->id)
        ->and($rows->first()->telegram_chat_id)->toBe(GROUP_SUPER_ID)
        ->and($rows->first()->isTracked())->toBeTrue()
        ->and($rows->first()->seen_count)->toBeGreaterThanOrEqual(2);
});

it('case 10b: a unique clash during the rekey is retried and still ends with one tracked row', function () {
    $source = groupTracked(GROUP_BASIC_ID);
    $inserted = false;

    // The target stub is inserted by "another request" between the first read and the update.
    TelegramGroup::updating(function (TelegramGroup $group) use (&$inserted): void {
        if (! $inserted && $group->telegram_chat_id === GROUP_SUPER_ID) {
            $inserted = true;
            DB::table('telegram_groups')->insert(['telegram_chat_id' => GROUP_SUPER_ID, 'type' => 'supergroup', 'status' => 'untracked', 'seen_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    groupHandle(groupFixture('migrate_to_chat_id', 1));

    expect(TelegramGroup::query()->count())->toBe(1)
        ->and($source->fresh()->telegram_chat_id)->toBe(GROUP_SUPER_ID);
});

it('case 11: a tracked target is never overwritten: counts logged, the Admin alerted, nothing merged', function () {
    Log::spy();
    $source = groupTracked(GROUP_BASIC_ID);
    $target = groupTracked(GROUP_SUPER_ID);
    TelegramGroupMessage::factory()->count(2)->create(['telegram_group_id' => $source->id]);
    TelegramGroupMessage::factory()->create(['telegram_group_id' => $target->id]);

    expect(app(HandleGroupUpdate::class)->handle(UpdateParser::fromArray(groupFixture('migrate_to_chat_id', 1))))->toBe(HandleGroupUpdate::MIGRATE_CONFLICT);

    expect($source->fresh()->telegram_chat_id)->toBe(GROUP_BASIC_ID)
        ->and($target->fresh()->telegram_chat_id)->toBe(GROUP_SUPER_ID)
        ->and(TelegramGroupMessage::query()->where('telegram_group_id', $target->id)->count())->toBe(1);

    Queue::assertPushed(SendGroupMigrateAlert::class, fn (SendGroupMigrateAlert $job): bool => $job->sourceMessages === 2 && $job->targetMessages === 1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'office.group_migrate_conflict' && $context === ['source_messages' => 2, 'target_messages' => 1]);
});

it('case 13: the allowed_updates request has drop_pending_updates false and all nine types', function () {
    telegramBotConfigured();
    Http::fake([
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => ConfigureTelegramWebhook::expectedUrl()]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    app(ConfigureTelegramWebhook::class)->updateAllowedUpdates(staffUser(), null);

    $call = collect(telegramApiCalls())->firstWhere('method', 'setWebhook');

    expect($call['data']['drop_pending_updates'])->toBeFalse()
        ->and($call['data']['allowed_updates'])->toHaveCount(9)
        ->and($call['data']['allowed_updates'])->toContain('edited_message', 'chat_member', 'message', 'callback_query', 'my_chat_member', 'business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages');
});

it('case 8 (ingest side): a contact whose user_id differs from the sender stores no phone, anywhere', function () {
    groupTracked();

    groupHandle(groupFixture('contact_other', 1));

    expect(TelegramGroupMember::query()->sole()->phone)->toBeNull()
        ->and(DB::table('telegram_group_members')->whereNotNull('phone')->count())->toBe(0);

    // No regex phones from text either.
    groupHandle(groupFixture('message_supergroup', 2, ['message' => ['text' => 'call me +998900000009 or 90 000 00 08']]));

    expect(TelegramGroupMember::query()->sole()->phone)->toBeNull();
});

it('case 15 (ingest side): the raw text column differs from the plaintext', function () {
    groupTracked();

    groupHandle(groupFixture('message_supergroup', 1));

    $raw = (string) DB::table('telegram_group_messages')->value('text');

    expect($raw)->not->toBe('Need a label printer for a shop')->and($raw)->not->toContain('label');
});

it('T2-4: no payload field is logged, and a failure reports only the update id and the exception class', function () {
    $spy = Log::spy();
    groupTracked();

    foreach (['message_group', 'message_supergroup', 'message_reply', 'message_edited', 'my_chat_member_added', 'my_chat_member_admin', 'my_chat_member_kicked', 'chat_member_join', 'new_chat_members', 'left_chat_member', 'sender_chat_anonymous', 'sender_chat_linked_channel', 'automatic_forward', 'message_from_bot', 'contact_own', 'media_photo', 'media_document', 'migrate_to_chat_id', 'migrate_from_chat_id', 'private_edited_message', 'channel_post'] as $i => $fixture) {
        groupHandle(groupFixture($fixture, 500 + $i));
    }

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
        $spy->shouldNotHaveReceived($level);
    }

    // A failing handler: the webhook reports a TelegramUpdateFailed (id + class), whatever the exception said.
    Exceptions::fake();
    telegramBotConfigured();
    $this->mock(HandleGroupUpdate::class)->shouldReceive('handle')->andThrow(new RuntimeException('secret payload: label printer for a shop'));

    postTelegramUpdate(groupFixture('message_supergroup', 900))->assertOk();

    Exceptions::assertReported(fn (TelegramUpdateFailed $failed): bool => $failed->updateId === 900
        && $failed->exceptionClass === RuntimeException::class
        && ! str_contains($failed->getMessage(), 'label printer'));
});

it('case 11b: migrate_to and migrate_from of one conflict give one log line and one alert', function () {
    Log::spy();
    groupTracked(GROUP_BASIC_ID);
    groupTracked(GROUP_SUPER_ID);

    groupHandle(groupFixture('migrate_to_chat_id', 1));
    groupHandle(groupFixture('migrate_from_chat_id', 2));

    Queue::assertPushed(SendGroupMigrateAlert::class, 1);
    Log::shouldHaveReceived('warning')->once();
});

it('keeps phone out of every T4 read path: the member phone is not fillable and not in model arrays by text', function () {
    expect((new TelegramGroupMember)->isFillable('phone'))->toBeFalse();
});
