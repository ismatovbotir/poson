<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C5 §19.2: the messages of a website chat. Visitor text is stored
 * redacted (ChatRedactor); bot rows keep the server-stored buttons (at most
 * 10, product buttons by id only, WC-16) and the rule that answered. Deleted
 * with the chat (cascade). chat_ai_turn_id is the Plan 33 B hook for the AI
 * badge (nullOnDelete: the 30-day AI prune keeps the message).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('web_chat_id')->constrained('web_chats')->cascadeOnDelete();
            $table->string('author', 10);
            $table->text('text');
            $table->json('buttons')->nullable();
            $table->foreignId('chat_rule_id')->nullable()->constrained('chat_rules')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('chat_ai_turn_id')->nullable()->constrained('chat_ai_turns')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['web_chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_chat_messages');
    }
};
