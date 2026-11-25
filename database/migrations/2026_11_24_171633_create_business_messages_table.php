<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §2: messages of client chats only (stored from the mark on). text
 * is encrypted at rest (Eloquent "encrypted" cast, so a text column, VO-5);
 * media holds safe metadata only (UpdateParser media params, never a
 * file_id). A Telegram delete nulls text and media and sets deleted_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_chat_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_message_id');
            $table->string('author', 8);
            $table->string('kind', 12);
            $table->text('text')->nullable();
            $table->json('media')->nullable();
            $table->string('media_group_id', 32)->nullable();
            $table->bigInteger('reply_to_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['business_chat_id', 'telegram_message_id']);
            $table->index(['business_chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_messages');
    }
};
