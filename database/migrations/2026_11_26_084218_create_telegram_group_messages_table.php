<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §2-3 (OG-11): messages of tracked groups only. text is encrypted at
 * rest (Eloquent EncryptedText cast, so a text column); media holds safe
 * metadata only, never a file_id.
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $table->unique(['telegram_group_id', 'telegram_message_id']);
            $table->index(['telegram_group_id', 'id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_messages');
    }
};
