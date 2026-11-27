<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §2-3 (OG-11): messages of tracked groups only. text is encrypted at
 * rest (Eloquent EncryptedText cast, so a text column); media holds safe
 * metadata only, never a file_id.
 *
 * Hotfix 2026-11-27 (decision log, exception to the frozen-migrations rule):
 * the default unique index name was 68 chars and MySQL (limit 64) failed after
 * CREATE TABLE, leaving a half-built empty table and no migrations row. The
 * unique now has a short explicit name, and up() drops that leftover table
 * only when it is empty (it refuses when it holds rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_group_messages')) {
            if (DB::table('telegram_group_messages')->exists()) {
                throw new RuntimeException('telegram_group_messages already holds rows; fix it by hand before migrating.');
            }

            Schema::drop('telegram_group_messages');
        }

        Schema::create('telegram_group_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('telegram_group_members')->cascadeOnDelete();
            $table->bigInteger('telegram_message_id');
            $table->string('kind', 12);
            $table->text('text')->nullable();
            $table->json('media')->nullable();
            $table->string('media_group_id', 32)->nullable();
            $table->bigInteger('reply_to_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['telegram_group_id', 'telegram_message_id'], 'tg_group_messages_group_message_unique');
            $table->index(['telegram_group_id', 'id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_messages');
    }
};
