<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C5 §19.2 (Plan 33 W-10 / AI-21): chat_ai_turns.web_chat_id gets
 * its foreign key, so deleting a website chat deletes its AI turns. Any
 * web_chat_id without a matching chat is nulled first (idempotent; there
 * should be none, the web channel had no AI turns before C5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_ai_turns')
            ->whereNotNull('web_chat_id')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('web_chats')->whereColumn('web_chats.id', 'chat_ai_turns.web_chat_id'))
            ->update(['web_chat_id' => null]);

        Schema::table('chat_ai_turns', function (Blueprint $table): void {
            $table->foreign('web_chat_id')->references('id')->on('web_chats')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_ai_turns', function (Blueprint $table): void {
            $table->dropForeign(['web_chat_id']);
        });
    }
};
