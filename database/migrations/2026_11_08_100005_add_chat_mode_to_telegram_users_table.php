<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1 / CB-4: who answers a registered customer's text, the bot
 * ("bot", the default) or staff ("manager"), the server-side button list of
 * the last bot answer, the time of the last hand-off (information only) and
 * the time of the last staff reply (what "waiting for staff" and the 8 hour
 * return-to-bot clock are based on).
 *
 * Data step (backfill(), query builder only, idempotent, no Telegram calls):
 *  - everyone already in a human conversation stays with staff, so enabling
 *    the bot never takes a waiting customer away from the inbox: unread
 *    messages, an open conversation with any traffic, or an inbound or staff
 *    message in the last 24 hours become "manager";
 *  - last_staff_reply_at = the newest staff message of the conversation, or
 *    null. handoff_at is NOT touched, so a customer who was read but never
 *    answered still counts as waiting (open, last inbound newer than the last
 *    staff reply).
 * A second run changes nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->string('chat_mode', 10)->default('bot')->after('conversation_status');
            $table->json('bot_dialog')->nullable()->after('chat_mode');
            $table->timestamp('handoff_at')->nullable()->after('bot_dialog');
            $table->timestamp('last_staff_reply_at')->nullable()->after('handoff_at');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        $since = now()->subDay();

        DB::table('telegram_users')
            ->where('chat_mode', 'bot')
            ->where(function ($query) use ($since): void {
                $query->where('unread_count', '>', 0)
                    ->orWhere(fn ($open) => $open->where('conversation_status', 'open')->whereNotNull('last_message_at'))
                    ->orWhereExists(function ($recent) use ($since): void {
                        $recent->select(DB::raw(1))
                            ->from('telegram_messages')
                            ->whereColumn('telegram_messages.telegram_user_id', 'telegram_users.id')
                            ->where('telegram_messages.created_at', '>=', $since)
                            ->where(fn ($who) => $who->where('telegram_messages.direction', 'in')->orWhereNotNull('telegram_messages.user_id'));
                    });
            })
            ->update(['chat_mode' => 'manager']);

        DB::table('telegram_users')->update([
            'last_staff_reply_at' => DB::raw('(select max(telegram_messages.created_at) from telegram_messages where telegram_messages.telegram_user_id = telegram_users.id and telegram_messages.user_id is not null)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn(['chat_mode', 'bot_dialog', 'handoff_at', 'last_staff_reply_at']);
        });
    }
};
