<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 14 v2: inbound and outbound bot messages (text and media metadata
 * only; files are copied to the team chat, never stored here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->string('kind', 16);
            $table->text('text')->nullable();
            $table->json('media')->nullable();
            $table->string('media_group_id', 32)->nullable();

            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10);
            $table->smallInteger('error_code')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->string('forward_status', 16)->nullable();
            $table->unsignedBigInteger('forwarded_message_id')->nullable();
            $table->smallInteger('forward_error_code')->nullable();

            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['telegram_user_id', 'direction', 'telegram_message_id'], 'telegram_messages_user_dir_msg_unique');
            $table->index(['telegram_user_id', 'id']);
            $table->index(['forward_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
