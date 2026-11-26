<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\BusinessChatKind;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramParty;
use App\Jobs\FetchGroupAdministrators;
use App\Models\BusinessChat;
use App\Models\BusinessConnection;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';

/*
| Plan 44 T4: the group ingest (HandleGroupUpdate) on the hand-written fixtures.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    groupsReady();
});

it('stores a stub only for an untracked group: no text, no members', function () {
    expect(groupHandle(groupFixture('message_supergroup', 1)))->toBe(HandleTelegramUpdate::GROUP);
    groupHandle(groupFixture('message_reply', 2));

    $group = TelegramGroup::query()->sole();

    expect($group->telegram_chat_id)->toBe(GROUP_SUPER_ID)
        ->and($group->status)->toBe(GroupStatus::Untracked)
        ->and($group->type)->toBe('supergroup')
        ->and($group->title)->toBe('Fake Supergroup')
        ->and($group->seen_count)->toBe(2)
        ->and($group->last_message_at)->not->toBeNull()
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupMessage::query()->count())->toBe(0);
});

it('does nothing but the dedupe while the groups switch or the office ingest is off', function (array $settings) {
    officeSettings($settings);

    expect(groupHandle(groupFixture('message_supergroup', 1)))->toBe(HandleTelegramUpdate::GROUP)
        ->and(TelegramGroup::query()->count())->toBe(0)
        ->and(DB::table('telegram_updates')->count())->toBe(1);

    groupHandle(groupFixture('message_supergroup', 1));

    expect(DB::table('telegram_updates')->count())->toBe(1);
})->with([
    'groups off' => [['groups_enabled' => false]],
    'ingest off' => [['ingest_enabled' => false]],
]);

it('takes the bot status from my_chat_member and makes no row for a bot that left an unknown group', function () {
    groupHandle(groupFixture('my_chat_member_added', 1));

    expect(TelegramGroup::query()->sole())->bot_status->toBe('member')->bot_is_admin->toBeFalse();

    groupHandle(groupFixture('my_chat_member_admin', 2));

    expect(TelegramGroup::query()->sole())->bot_status->toBe('administrator')->bot_is_admin->toBeTrue();

    TelegramGroup::query()->delete();
    groupHandle(groupFixture('my_chat_member_kicked', 3));

    expect(TelegramGroup::query()->count())->toBe(0);
});

it('keeps an ignored group at its counters: the title is not refreshed and nothing is stored', function () {
    TelegramGroup::factory()->ignored()->create(['telegram_chat_id' => GROUP_SUPER_ID, 'title' => null, 'seen_count' => 4]);

    expect(groupHandle(groupFixture('message_supergroup', 1)))->toBe(HandleTelegramUpdate::GROUP);

    $group = TelegramGroup::query()->sole();

    expect($group->seen_count)->toBe(5)
        ->and($group->title)->toBeNull()
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupMessage::query()->count())->toBe(0);
});

it('stores the message of a tracked group encrypted, with the author as a member and the reply link', function () {
    groupTracked();

    groupHandle(groupFixture('message_supergroup', 1));
    groupHandle(groupFixture('message_reply', 2));

    $message = TelegramGroupMessage::query()->where('telegram_message_id', 21)->sole();
    $raw = DB::table('telegram_group_messages')->where('id', $message->id)->value('text');

    expect($message->text)->toBe('Need a label printer for a shop')
        ->and($raw)->not->toContain('label printer')
        ->and($message->kind)->toBe(TelegramMessageKind::Text)
        ->and(TelegramGroupMessage::query()->where('telegram_message_id', 22)->sole()->reply_to_message_id)->toBe(21);

    $member = TelegramGroupMember::query()->where('telegram_id', GROUP_CLIENT_ID)->sole();

    expect($member->first_name)->toBe('Fake Client')
        ->and($member->username)->toBe('fake_client')
        ->and($member->role)->toBe(GroupMemberRole::Unknown)
        ->and($member->role_set_at)->toBeNull()
        ->and($member->message_count)->toBe(1)
        ->and(TelegramGroup::query()->sole()->analysis_due_at)->not->toBeNull();

    groupHandle(groupFixture('message_supergroup', 3));

    expect(TelegramGroupMessage::query()->where('telegram_message_id', 21)->count())->toBe(1);
});

it('suggests a member role: owner and employee party staff, client party or client business chat clients, else unknown', function (string $source, GroupMemberRole $expected) {
    groupTracked();

    match ($source) {
        'owner' => officeSettings(['owner_telegram_ids' => [GROUP_CLIENT_ID]]),
        'employee' => TelegramUser::factory()->create(['telegram_id' => GROUP_CLIENT_ID, 'party' => TelegramParty::Employee]),
        'client' => TelegramUser::factory()->create(['telegram_id' => GROUP_CLIENT_ID, 'party' => TelegramParty::Client]),
        'business' => BusinessChat::factory()->create(['business_connection_id' => BusinessConnection::factory()->create()->id, 'telegram_chat_id' => GROUP_CLIENT_ID, 'kind' => BusinessChatKind::Client]),
        default => null,
    };

    groupHandle(groupFixture('message_supergroup', 1));

    expect(TelegramGroupMember::query()->sole()->role)->toBe($expected);
})->with([
    ['owner', GroupMemberRole::Employee],
    ['employee', GroupMemberRole::Employee],
    ['client', GroupMemberRole::Client],
    ['business', GroupMemberRole::Client],
    ['none', GroupMemberRole::Unknown],
]);

it('keeps a staff-set role when the member speaks again', function () {
    $group = groupTracked();
    TelegramGroupMember::factory()->employee()->create(['telegram_group_id' => $group->id, 'telegram_id' => GROUP_CLIENT_ID]);

    groupHandle(groupFixture('message_supergroup', 1));

    expect(TelegramGroupMember::query()->sole()->role)->toBe(GroupMemberRole::Employee);
});

it('makes a bot sender a bot member and stores none of its messages', function () {
    groupTracked();

    groupHandle(groupFixture('message_from_bot', 1));

    expect(TelegramGroupMember::query()->sole())->role->toBe(GroupMemberRole::Bot)->is_bot->toBeTrue()
        ->and(TelegramGroupMessage::query()->count())->toBe(0);
});

it('counts sender_chat and automatic forwards without storing them or making members', function (string $fixture) {
    groupTracked(attributes: ['seen_count' => 0]);

    groupHandle(groupFixture($fixture, 1));

    expect(TelegramGroup::query()->sole()->seen_count)->toBe(1)
        ->and(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupMessage::query()->count())->toBe(0);
})->with(['sender_chat_anonymous', 'sender_chat_linked_channel', 'automatic_forward']);

it('edits only a message the same author sent and we stored', function () {
    groupTracked();
    groupHandle(groupFixture('message_supergroup', 1));

    // Another author for the same message id: nothing changes.
    groupHandle(groupFixture('message_edited', 2, ['edited_message' => ['from' => ['id' => GROUP_STAFF_ID, 'first_name' => 'Fake Staff']]]));

    expect(TelegramGroupMessage::query()->sole()->text)->toBe('Need a label printer for a shop');

    groupHandle(groupFixture('message_edited', 3));

    $message = TelegramGroupMessage::query()->sole();

    expect($message->text)->toBe('Need two label printers for a shop')
        ->and($message->edited_at)->not->toBeNull()
        ->and(TelegramGroupMessage::query()->count())->toBe(1);
});

it('creates nothing for an edit of a message that was never stored', function () {
    groupTracked();

    groupHandle(groupFixture('message_edited', 1));

    expect(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(TelegramGroupMember::query()->count())->toBe(0);
});

it('takes joins and leaves from service messages and chat_member', function () {
    groupTracked();

    groupHandle(groupFixture('new_chat_members', 1));

    $client = TelegramGroupMember::query()->where('telegram_id', GROUP_CLIENT_ID)->sole();
    $bot = TelegramGroupMember::query()->where('telegram_id', GROUP_OTHER_BOT_ID)->sole();

    expect($client->member_status)->toBe('member')
        ->and($bot->role)->toBe(GroupMemberRole::Bot)
        ->and(TelegramGroupMessage::query()->count())->toBe(0);

    groupHandle(groupFixture('left_chat_member', 2));

    expect($client->fresh()->member_status)->toBe('left');

    groupHandle(groupFixture('chat_member_join', 3));

    expect($client->fresh()->member_status)->toBe('member');
});

it('stores a shared phone only on the member and only for the sender\'s own contact', function () {
    groupTracked();

    groupHandle(groupFixture('contact_other', 1));

    $member = TelegramGroupMember::query()->sole();

    expect($member->phone)->toBeNull();

    groupHandle(groupFixture('contact_own', 2));

    expect($member->fresh()->phone)->toBe('+998900000001')
        ->and(DB::table('telegram_group_messages')->get()->map(fn ($row) => json_encode($row))->implode(' '))->not->toContain('9989000');
});

it('stores media without a file_id and with a cleaned file name', function (string $fixture) {
    groupTracked();

    groupHandle(groupFixture($fixture, 1));

    $row = DB::table('telegram_group_messages')->sole();

    expect(json_encode($row))->not->toContain('FAKE_FILE_ID')->not->toContain('FAKE_UNIQUE_ID')->not->toContain('file_id')
        ->and((string) $row->media)->not->toContain('900000003');

    if ($fixture === 'media_photo') {
        expect(json_decode((string) $row->media, true))->toBe(['file_size' => 9800, 'width' => 800, 'height' => 600]);
    }
})->with(['media_photo', 'media_document']);

it('stops at 500 stored messages per group and day (counters still move)', function () {
    config(['office.limits.stored_messages_per_group_daily' => 3]);
    groupTracked();

    foreach (range(1, 5) as $i) {
        groupHandle(groupFixture('message_supergroup', $i, ['message' => ['message_id' => 100 + $i]]));
    }

    expect(TelegramGroupMessage::query()->count())->toBe(3)
        ->and(TelegramGroup::query()->sole()->seen_count)->toBe(6);
});

it('stops ingest at once when the bot is kicked and resumes when it is added again', function () {
    groupTracked();
    groupHandle(groupFixture('message_supergroup', 1));

    groupHandle(groupFixture('my_chat_member_kicked', 2));

    expect(TelegramGroup::query()->sole())->bot_status->toBe('kicked')->analysis_due_at->toBeNull();

    groupHandle(groupFixture('message_reply', 3));

    expect(TelegramGroupMessage::query()->count())->toBe(1);

    groupHandle(groupFixture('my_chat_member_added', 4));
    groupHandle(groupFixture('message_reply', 5));

    expect(TelegramGroupMessage::query()->count())->toBe(2);
});

it('stores a group command like any other text for a tracked group and nothing for an untracked one', function () {
    groupHandle(groupFixture('start_command', 1));

    expect(TelegramGroupMessage::query()->count())->toBe(0);

    TelegramGroup::query()->update(['status' => GroupStatus::Tracked->value]);
    groupHandle(groupFixture('start_command', 2));

    expect(TelegramGroupMessage::query()->sole()->text)->toBe('/start@fake_poson_bot');
});

it('rekeys a tracked source on migrate_to_chat_id and again (idempotent) on migrate_from_chat_id', function () {
    $group = groupTracked(GROUP_BASIC_ID);
    groupHandle(groupFixture('message_group', 1));

    groupHandle(groupFixture('migrate_to_chat_id', 2));

    $group->refresh();

    expect($group->telegram_chat_id)->toBe(GROUP_SUPER_ID)
        ->and($group->type)->toBe('supergroup')
        ->and($group->isTracked())->toBeTrue()
        ->and(TelegramGroupMessage::query()->count())->toBe(1);

    groupHandle(groupFixture('migrate_from_chat_id', 3));

    expect(TelegramGroup::query()->count())->toBe(1)
        ->and($group->fresh()->telegram_chat_id)->toBe(GROUP_SUPER_ID);

    groupHandle(groupFixture('message_supergroup', 4));

    expect(TelegramGroupMessage::query()->count())->toBe(2);
});

it('rekeys on migrate_from_chat_id alone', function () {
    $group = groupTracked(GROUP_BASIC_ID);

    groupHandle(groupFixture('migrate_from_chat_id', 1));

    expect($group->fresh()->telegram_chat_id)->toBe(GROUP_SUPER_ID);
});

it('does not move an untracked or unknown source', function () {
    TelegramGroup::factory()->create(['telegram_chat_id' => GROUP_BASIC_ID]);

    groupHandle(groupFixture('migrate_to_chat_id', 1));

    expect(TelegramGroup::query()->pluck('telegram_chat_id')->all())->toBe([GROUP_BASIC_ID]);
});

it('asks for the administrator list when a tracked group makes the bot an admin, at most once in 10 minutes', function () {
    Queue::fake();
    Cache::flush();
    groupTracked(attributes: ['bot_status' => 'member', 'bot_is_admin' => false]);

    groupHandle(groupFixture('my_chat_member_admin', 1));
    groupHandle(groupFixture('my_chat_member_admin', 2));

    Queue::assertPushed(FetchGroupAdministrators::class, 1);
});

/**
 * Queries one update causes (the update_id dedupe row and the settings read included).
 *
 * @param  array<string, mixed>  $payload
 */
function groupQueryCount(array $payload): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    groupHandle($payload);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the query count of the hot paths small', function () {
    $this->freezeTime();

    // An untracked stub (known group): dedupe row, settings, group read, one counter update.
    groupHandle(groupFixture('message_supergroup', 1));
    $stub = groupQueryCount(groupFixture('message_supergroup', 2));

    // A tracked group, a message from an existing member (same second: no last_seen / due writes).
    TelegramGroup::query()->update(['status' => GroupStatus::Tracked->value]);
    groupHandle(groupFixture('message_supergroup', 3, ['message' => ['message_id' => 301]]));
    $tracked = groupQueryCount(groupFixture('message_supergroup', 4, ['message' => ['message_id' => 302]]));

    // A second later: plus the member's last_seen and the group's analysis_due writes.
    $this->travel(2)->seconds();
    $later = groupQueryCount(groupFixture('message_supergroup', 5, ['message' => ['message_id' => 303]]));

    expect($stub)->toBeLessThanOrEqual(4)->and($tracked)->toBeLessThanOrEqual(9)->and($later)->toBeLessThanOrEqual(11);
});

it('does not burn the daily cap on a duplicate message id (L1)', function () {
    config(['office.limits.stored_messages_per_group_daily' => 2]);
    groupTracked();

    foreach ([1, 2, 3] as $update) {
        groupHandle(groupFixture('message_supergroup', $update));
    }

    groupHandle(groupFixture('message_supergroup', 4, ['message' => ['message_id' => 99]]));

    expect(TelegramGroupMessage::query()->count())->toBe(2);
});

it('sets a left member back to member when they speak again and keeps admin statuses', function () {
    $group = groupTracked();
    $left = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'telegram_id' => GROUP_CLIENT_ID, 'member_status' => 'left']);
    $admin = TelegramGroupMember::factory()->create(['telegram_group_id' => $group->id, 'telegram_id' => GROUP_STAFF_ID, 'member_status' => 'admin']);

    groupHandle(groupFixture('message_supergroup', 1));
    groupHandle(groupFixture('message_reply', 2));

    expect($left->fresh()->member_status)->toBe('member')->and($admin->fresh()->member_status)->toBe('admin');
});

it('does not touch last_message_at on an edit and bumps analysis_due_at at most once a minute', function () {
    $this->freezeTime();
    $group = groupTracked();
    groupHandle(groupFixture('message_supergroup', 1));
    $group->refresh();
    $last = $group->last_message_at;
    $due = $group->analysis_due_at;

    $this->travel(20)->seconds();
    groupHandle(groupFixture('message_edited', 2));

    $group->refresh();

    expect($group->last_message_at->equalTo($last))->toBeTrue()
        ->and($group->analysis_due_at->equalTo($due))->toBeTrue();

    $this->travel(2)->minutes();
    groupHandle(groupFixture('message_edited', 3));

    expect($group->fresh()->analysis_due_at->gt($due))->toBeTrue();
});
