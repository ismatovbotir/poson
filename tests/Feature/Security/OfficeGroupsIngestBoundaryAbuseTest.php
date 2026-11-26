<?php

use App\Actions\Office\UpsertGroupMember;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Jobs\FetchGroupAdministrators;
use App\Jobs\SendGroupMigrateAlert;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';

/*
| Plan 44 §7/§10/§11 abuse cases for the group ingest boundaries (T4):
| stale queued jobs, hostile migrate ids, foreign edits, member floods,
| hostile text and the new-group cap.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    groupsReady();
});

function runAdminFetch(int $groupId): void
{
    (new FetchGroupAdministrators($groupId))->handle(app(TelegramClient::class), app(UpsertGroupMember::class));
}

it('A1: an admin fetch queued before groups were switched off or the bot was kicked makes no call and no member rows', function (string $change) {
    telegramBotConfigured();
    Http::fake();
    $group = groupTracked();

    // Queued while everything was on.
    $queued = new FetchGroupAdministrators($group->id);

    if ($change === 'groups off') {
        officeSettings(['groups_enabled' => false]);
    } else {
        groupHandle(groupFixture('my_chat_member_kicked', 1));
        expect($group->fresh()->bot_status)->toBe('kicked');
    }

    $queued->handle(app(TelegramClient::class), app(UpsertGroupMember::class));

    Http::assertNothingSent();
    expect(TelegramGroupMember::query()->count())->toBe(0);
})->with(['groups off', 'bot kicked']);

it('A2: a hostile migrate (positive target, target equal to the chat, ignored source) moves nothing', function (string $case) {
    Queue::fake();
    $source = match ($case) {
        'ignored source' => TelegramGroup::factory()->ignored()->create(['telegram_chat_id' => GROUP_BASIC_ID, 'type' => 'group']),
        default => groupTracked(GROUP_BASIC_ID),
    };

    $overrides = match ($case) {
        'positive target' => ['message' => ['migrate_to_chat_id' => 9990020001]],
        'target equals chat' => ['message' => ['migrate_to_chat_id' => GROUP_BASIC_ID]],
        default => [],
    };

    $before = DB::table('telegram_groups')->get()->map(fn ($row) => (array) $row)->all();

    groupHandle(groupFixture('migrate_to_chat_id', 1, $overrides));

    $after = DB::table('telegram_groups')->get()->map(fn ($row) => (array) $row)->all();

    expect(TelegramGroup::query()->count())->toBe(1)
        ->and($source->fresh()->telegram_chat_id)->toBe(GROUP_BASIC_ID)
        ->and(TelegramGroup::query()->where('telegram_chat_id', GROUP_SUPER_ID)->exists())->toBeFalse()
        ->and(collect($after)->map(fn (array $row) => collect($row)->except(['seen_count', 'updated_at', 'last_message_at', 'title'])->all())->all())
        ->toBe(collect($before)->map(fn (array $row) => collect($row)->except(['seen_count', 'updated_at', 'last_message_at', 'title'])->all())->all());

    Queue::assertNothingPushed();
})->with(['positive target', 'target equals chat', 'ignored source']);

it('A3: an edit sent with another member\'s from.id leaves the raw stored text untouched', function () {
    groupTracked();
    groupHandle(groupFixture('message_supergroup', 1, ['message' => ['message_id' => 21]]));

    $rawBefore = DB::table('telegram_group_messages')->value('text');
    expect($rawBefore)->not->toBeNull();

    // The edit claims another sender (the staff member), same message id.
    groupHandle(groupFixture('message_edited', 2, ['edited_message' => ['text' => 'HIJACKED', 'from' => ['id' => GROUP_STAFF_ID, 'first_name' => 'Fake Staff']]]));

    expect(DB::table('telegram_group_messages')->value('text'))->toBe($rawBefore)
        ->and(DB::table('telegram_group_messages')->value('edited_at'))->toBeNull()
        ->and(TelegramGroupMessage::query()->count())->toBe(1);
});

it('A4: one message with 15 new_chat_members creates at most 10 member rows', function () {
    groupTracked();

    $members = collect(range(1, 15))->map(fn (int $i) => ['id' => 9990030000 + $i, 'is_bot' => false, 'first_name' => 'Fake '.$i])->all();

    groupHandle(groupFixture('new_chat_members', 1, ['message' => ['new_chat_members' => $members]]));

    expect(TelegramGroupMember::query()->count())->toBeLessThanOrEqual(10)
        ->and(TelegramGroupMember::query()->where('telegram_id', 9990030015)->exists())->toBeFalse();
});

it('A5: a 5,000-character text with bidi and zero-width characters is stored cleaned and truncated without error', function () {
    groupTracked();

    $hostile = str_repeat("\u{202E}ab\u{200B}c\u{2066}d\u{FEFF}\x07 ", 1000);
    expect(mb_strlen($hostile))->toBeGreaterThan(5000);

    $format = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200D}\x{FEFF}]/u';
    $payload = groupFixture('message_supergroup', 1, ['message' => ['text' => $hostile, 'chat' => ['title' => "Fake\u{202E} Super\u{200B}group"], 'from' => ['first_name' => "Fake\u{2067} Cli\u{FEFF}ent"]]]);

    expect(groupHandle($payload))->toBe(HandleTelegramUpdate::GROUP);

    $text = TelegramGroupMessage::query()->sole()->text;

    expect($text)->not->toMatch($format)
        ->and((string) TelegramGroup::query()->sole()->title)->not->toMatch($format)
        ->and((string) TelegramGroupMember::query()->sole()->first_name)->not->toMatch($format);

    expect(mb_strlen($text))->toBeLessThanOrEqual(4096)
        ->and($text)->not->toMatch('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/')
        ->and($text)->toContain('ab');
});

it('A6: the 50th and 51st new groups back to back and interleaved never exceed 50 stubs', function () {
    $stub = fn (int $i) => groupHandle(groupFixture('message_supergroup', $i, ['message' => ['chat' => ['id' => -1009990200000 - $i]]]));

    foreach (range(1, 49) as $i) {
        $stub($i);
    }

    expect(TelegramGroup::query()->count())->toBe(49);

    // 50th, 51st and a repeat of a known group in between.
    $stub(50);
    groupHandle(groupFixture('message_supergroup', 200, ['message' => ['chat' => ['id' => -1009990200001]]]));
    $stub(51);
    $stub(52);
    groupHandle(groupFixture('my_chat_member_added', 201, ['my_chat_member' => ['chat' => ['id' => -1009990200053]]]));
    groupHandle(groupFixture('message_supergroup', 202, ['message' => ['chat' => ['id' => -1009990200050]]]));

    expect(TelegramGroup::query()->where('status', 'untracked')->count())->toBe(50)
        ->and(TelegramGroup::query()->where('telegram_chat_id', -1009990200050)->value('seen_count'))->toBe(2)
        ->and(TelegramGroup::query()->whereIn('telegram_chat_id', [-1009990200051, -1009990200052, -1009990200053])->exists())->toBeFalse();
});

it('A7: with groups off, or for an untracked group, no admin fetch and no migrate alert is ever queued', function (string $case) {
    Queue::fake();
    telegramBotConfigured();

    if ($case === 'groups off') {
        officeSettings(['groups_enabled' => false]);
        groupTracked();
        groupTracked(GROUP_BASIC_ID);
        $fixtures = ['my_chat_member_admin', 'my_chat_member_added', 'migrate_to_chat_id', 'message_supergroup'];
    } else {
        $fixtures = ['my_chat_member_admin', 'my_chat_member_added', 'migrate_to_chat_id', 'migrate_from_chat_id', 'message_supergroup', 'chat_member_join'];
    }

    foreach ($fixtures as $i => $fixture) {
        groupHandle(groupFixture($fixture, $i + 1));
    }

    Queue::assertNotPushed(FetchGroupAdministrators::class);
    Queue::assertNotPushed(SendGroupMigrateAlert::class);

    if ($case === 'untracked group') {
        expect(TelegramGroup::query()->where('status', '!=', 'untracked')->count())->toBe(0);
    }
})->with(['groups off', 'untracked group']);
