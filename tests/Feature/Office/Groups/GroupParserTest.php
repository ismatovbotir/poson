<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Support\Telegram\Bot\BusinessUpdate;
use App\Support\Telegram\Bot\GroupUpdate;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';

/*
| Plan 44 OG-1 / T2-3: GroupUpdate is built only for group and supergroup
| message, edited_message, my_chat_member and chat_member; the parser is
| tolerant and never throws.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

it('builds a GroupUpdate for the four types of a group or supergroup', function (string $fixture, string $type, string $chatType) {
    $update = UpdateParser::fromArray(groupFixture($fixture, 1));

    expect($update)->toBeInstanceOf(GroupUpdate::class)
        ->and($update->type)->toBe($type)
        ->and($update->chatType)->toBe($chatType);
})->with([
    ['message_group', GroupUpdate::TYPE_MESSAGE, 'group'],
    ['message_supergroup', GroupUpdate::TYPE_MESSAGE, 'supergroup'],
    ['message_edited', GroupUpdate::TYPE_EDITED, 'supergroup'],
    ['my_chat_member_added', GroupUpdate::TYPE_BOT_MEMBER, 'supergroup'],
    ['chat_member_join', GroupUpdate::TYPE_MEMBER, 'supergroup'],
]);

it('turns a channel post, a private edited_message and a private chat_member into TYPE_OTHER, never a customer message', function (array $payload) {
    $update = UpdateParser::fromArray($payload);

    expect($update)->toBeInstanceOf(UpdateParser::class)
        ->and($update->type)->toBe(UpdateParser::TYPE_OTHER)
        ->and(groupHandle($payload))->toBe(HandleTelegramUpdate::IGNORED);
})->with([
    'channel post' => fn () => groupFixture('channel_post', 1),
    'private edited_message' => fn () => groupFixture('private_edited_message', 2),
    'private chat_member' => fn () => ['update_id' => 3, 'chat_member' => ['chat' => ['id' => GROUP_CLIENT_ID, 'type' => 'private'], 'from' => ['id' => GROUP_CLIENT_ID, 'is_bot' => false, 'first_name' => 'x']]],
    'edited channel post' => fn () => ['update_id' => 4, 'edited_channel_post' => ['chat' => ['id' => -1009990040001, 'type' => 'channel'], 'text' => 'x']],
    'channel chat_member' => fn () => ['update_id' => 5, 'chat_member' => ['chat' => ['id' => -1009990040001, 'type' => 'channel'], 'from' => ['id' => GROUP_STAFF_ID, 'is_bot' => false, 'first_name' => 'x']]],
]);

it('keeps business updates and private messages on their own paths', function () {
    expect(UpdateParser::fromArray(officeFixture('message_client', 1)))->toBeInstanceOf(BusinessUpdate::class)
        ->and(UpdateParser::fromArray(telegramPrivateUpdate(2)))->toBeInstanceOf(UpdateParser::class)
        ->and(UpdateParser::fromArray(['update_id' => 3, 'message' => ['chat' => ['id' => -1009990020001, 'type' => 'supergroup'], 'business_connection_id' => 'fake-conn-0001', 'text' => 'x']])->type)->toBe(UpdateParser::TYPE_OTHER);
});

it('reads the member and migration fields', function () {
    $joined = UpdateParser::fromArray(groupFixture('new_chat_members', 1));
    $to = UpdateParser::fromArray(groupFixture('migrate_to_chat_id', 2));
    $from = UpdateParser::fromArray(groupFixture('migrate_from_chat_id', 3));
    $admin = UpdateParser::fromArray(groupFixture('my_chat_member_admin', 4));

    expect(collect($joined->newMembers)->pluck('id')->all())->toBe([GROUP_CLIENT_ID, GROUP_OTHER_BOT_ID])
        ->and($to->migrateToChatId)->toBe(GROUP_SUPER_ID)
        ->and($from->migrateFromChatId)->toBe(GROUP_BASIC_ID)
        ->and($admin->memberStatus)->toBe('administrator');
});

it('survives hostile shapes: unusable ones end as TYPE_OTHER or are ignored, never an exception, never HTTP', function (array $payload, bool $isGroupUpdate) {
    $parsed = UpdateParser::fromArray($payload);

    expect($parsed === null || $isGroupUpdate === ($parsed instanceof GroupUpdate))->toBeTrue();

    groupsReady();
    $handled = groupHandle($payload);

    expect($handled)->toBeString();
    Http::assertNothingSent();
})->with([
    'no chat' => fn () => [['update_id' => 1, 'message' => ['from' => ['id' => GROUP_CLIENT_ID, 'is_bot' => false, 'first_name' => 'x'], 'text' => 'hi']], false],
    'chat is a string' => fn () => [groupFixture('message_supergroup', 2, ['message' => ['chat' => 'oops']]), false],
    'string chat id' => fn () => [groupFixture('message_supergroup', 3, ['message' => ['chat' => ['id' => '-1009990020001']]]), false],
    'positive chat id' => fn () => [groupFixture('message_supergroup', 4, ['message' => ['chat' => ['id' => 5]]]), false],
    'no from' => fn () => [(function () {
        $payload = groupFixture('message_supergroup', 5);
        unset($payload['message']['from']);

        return $payload;
    })(), true],
    'string from id' => fn () => [groupFixture('message_supergroup', 6, ['message' => ['from' => ['id' => '9990010001']]]), true],
    'from is a list' => fn () => [groupFixture('message_supergroup', 7, ['message' => ['from' => ['x']]]), true],
    'unknown keys' => fn () => [groupFixture('message_supergroup', 8, ['message' => ['zzz' => ['a' => 1], 'forum_topic_created' => ['name' => 'x']], 'future_update_key' => 1]), true],
    'null text' => fn () => [groupFixture('message_supergroup', 9, ['message' => ['text' => null]]), true],
    'array text' => fn () => [groupFixture('message_supergroup', 10, ['message' => ['text' => ['x']]]), true],
    'oversized title and text' => fn () => [groupFixture('message_supergroup', 11, ['message' => ['text' => str_repeat('я', 20000), 'chat' => ['title' => str_repeat('T', 5000)]]]), true],
    'string migrate ids' => fn () => [groupFixture('migrate_to_chat_id', 12, ['message' => ['migrate_to_chat_id' => '-1009990020001']]), false],
    'chat_member without a new member' => fn () => [['update_id' => 13, 'chat_member' => ['chat' => ['id' => GROUP_SUPER_ID, 'type' => 'supergroup'], 'from' => ['id' => 9990000002, 'is_bot' => false, 'first_name' => 'x']]], true],
    'status of a wrong type' => fn () => [groupFixture('my_chat_member_added', 14, ['my_chat_member' => ['new_chat_member' => ['status' => ['x']]]]), true],
    'huge new_chat_members' => fn () => [groupFixture('new_chat_members', 15, ['message' => ['new_chat_members' => array_map(fn (int $i): array => ['id' => 9990100000 + $i, 'is_bot' => false, 'first_name' => 'f'], range(1, 300))]]), true],
]);

it('cuts an oversized title and text instead of failing', function () {
    $update = UpdateParser::fromArray(groupFixture('message_supergroup', 1, ['message' => ['text' => str_repeat('я', 20000), 'chat' => ['title' => str_repeat('T', 5000)]]]));

    expect(mb_strlen((string) $update->title))->toBe(GroupUpdate::TITLE_LIMIT)
        ->and(mb_strlen((string) $update->text))->toBe(UpdateParser::TEXT_LIMIT);

    $many = UpdateParser::fromArray(groupFixture('new_chat_members', 2, ['message' => ['new_chat_members' => array_map(fn (int $i): array => ['id' => 9990100000 + $i, 'is_bot' => false, 'first_name' => 'f'], range(1, 300))]]));

    expect($many->newMembers)->toHaveCount(GroupUpdate::MAX_NEW_MEMBERS);
});

it('still records the dedupe row for an unusable group update', function () {
    groupsReady();

    groupHandle(groupFixture('message_supergroup', 77, ['message' => ['chat' => ['id' => 'x']]]));

    expect(DB::table('telegram_updates')->where('update_id', 77)->count())->toBe(1);
});
