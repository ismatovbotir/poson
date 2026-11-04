<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 21 B1 (TG-27 / T21-9): the team chat the copy (forwarded_message_id)
 * was sent to, so a later best-effort deleteMessage uses the stored pair and
 * never the current setting. Signed bigint (chat ids are negative), nullable,
 * no foreign key, no backfill: old rows stay null and are never called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->bigInteger('forwarded_chat_id')->nullable()->after('forwarded_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->dropColumn('forwarded_chat_id');
        });
    }
};
