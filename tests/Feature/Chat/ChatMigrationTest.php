<?php

use App\Enums\ChatMode;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1 / CB-4: the add_chat_mode_to_telegram_users data step is
 * query-builder only, idempotent and makes no Telegram call.
 */
function chatModeMigration(): Migration
{
    return require database_path('migrations/2026_11_08_100005_add_chat_mode_to_telegram_users_table.php');
}

function rerunChatModeMigration(): void
{
    $migration = chatModeMigration();
    $migration->down();
    // Rows keep their data; the columns come back with the default.
    $migration->up();
}

it('puts conversations that are already human into manager mode', function () {
    Http::preventStrayRequests();

    $quiet = TelegramUser::factory()->registered()->create();
    $unread = TelegramUser::factory()->registered()->create(['unread_count' => 3]);
    $open = TelegramUser::factory()->registered()->create(['last_message_at' => now()->subDays(40)]);
    $closed = TelegramUser::factory()->registered()->closed()->create(['last_message_at' => now()->subDays(40)]);
    $inbound = TelegramUser::factory()->registered()->closed()->create();
    $staff = TelegramUser::factory()->registered()->closed()->create();
    $old = TelegramUser::factory()->registered()->closed()->create();

    TelegramMessage::factory()->create(['telegram_user_id' => $inbound->id, 'direction' => 'in', 'created_at' => now()->subHours(2)]);
    TelegramMessage::factory()->create(['telegram_user_id' => $staff->id, 'direction' => 'out', 'user_id' => staffUser()->id, 'created_at' => now()->subHours(5)]);
    TelegramMessage::factory()->create(['telegram_user_id' => $old->id, 'direction' => 'in', 'created_at' => now()->subHours(30)]);

    rerunChatModeMigration();

    $mode = fn (TelegramUser $user) => DB::table('telegram_users')->where('id', $user->id)->value('chat_mode');

    expect(Schema::hasColumns('telegram_users', ['chat_mode', 'bot_dialog', 'handoff_at', 'last_staff_reply_at']))->toBeTrue()
        ->and($mode($quiet))->toBe('bot')
        ->and($mode($closed))->toBe('bot')
        ->and($mode($old))->toBe('bot')
        ->and($mode($unread))->toBe('manager')
        ->and($mode($open))->toBe('manager')
        ->and($mode($inbound))->toBe('manager')
        ->and($mode($staff))->toBe('manager')
        ->and(TelegramUser::query()->find($unread->id)->handoff_at)->toBeNull()
        ->and(TelegramUser::query()->find($staff->id)->last_staff_reply_at?->diffInMinutes(now()->subHours(5)))->toBeLessThan(1)
        ->and(TelegramUser::query()->find($inbound->id)->last_staff_reply_at)->toBeNull()
        ->and(TelegramUser::query()->find($quiet->id)->last_staff_reply_at)->toBeNull()
        ->and(TelegramUser::query()->find($unread->id)->chat_mode)->toBe(ChatMode::Manager);

});

it('is idempotent: running the data step again over migrated data changes nothing', function () {
    Http::preventStrayRequests();

    $unread = TelegramUser::factory()->registered()->create(['unread_count' => 1]);
    $answered = TelegramUser::factory()->registered()->closed()->create();
    TelegramMessage::factory()->create(['telegram_user_id' => $answered->id, 'direction' => 'out', 'user_id' => staffUser()->id, 'created_at' => now()->subHours(3)]);

    rerunChatModeMigration();
    $snapshot = DB::table('telegram_users')->orderBy('id')->get()->toJson();

    chatModeMigration()->backfill();
    chatModeMigration()->backfill();

    expect(DB::table('telegram_users')->orderBy('id')->get()->toJson())->toBe($snapshot)
        ->and(DB::table('telegram_users')->where('id', $unread->id)->value('chat_mode'))->toBe('manager')
        ->and(DB::table('telegram_users')->where('id', $answered->id)->value('last_staff_reply_at'))->not->toBeNull();

});

it('keeps a read but unanswered customer waiting, whatever the data step does', function () {
    $user = TelegramUser::factory()->registered()->create([
        'unread_count' => 0, 'last_message_at' => now()->subHours(9), 'last_inbound_at' => now()->subHours(9),
    ]);

    rerunChatModeMigration();

    $fresh = TelegramUser::query()->find($user->id);

    expect($fresh->chat_mode)->toBe(ChatMode::Manager)
        ->and($fresh->isWaitingForStaff())->toBeTrue()
        ->and($fresh->hasChatTtlExpired())->toBeFalse();
});
