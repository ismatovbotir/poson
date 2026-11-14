<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 30 §3 (P4, AI-21): the redacted AI conversation turns of the chat
 * bot. A user row holds the customer's message after ChatRedactor; an
 * assistant row the filtered answer (or nothing, for a turn that was not
 * answered) with its status, model, token usage and latency. Never a prompt,
 * only its hash. answers_turn_id is unique: one answer per user turn, ever.
 * Rows go with the Telegram conversation (cascade); web_chat_id gets its
 * foreign key with the website chat (phase C). Kept 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_turns', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 10);
            $table->foreignId('telegram_user_id')->nullable()->constrained('telegram_users')->cascadeOnDelete();
            $table->unsignedBigInteger('web_chat_id')->nullable();
            $table->foreignId('answers_turn_id')->nullable()->unique()->constrained('chat_ai_turns')->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('text');
            $table->string('status', 12)->nullable();
            $table->string('model', 40)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->string('stop_reason', 30)->nullable();
            $table->char('prompt_hash', 64)->nullable();
            $table->json('product_ids')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['telegram_user_id', 'id']);
            $table->index(['web_chat_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_turns');
    }
};
