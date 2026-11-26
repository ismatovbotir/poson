<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §2-3: a Telegram group or supergroup the bot is a member of. An
 * untracked row is a stub only (title, type, bot state, counters: OG-2); the
 * chat id is a bigint (negative for groups). Tracking is an Admin decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_groups', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('type', 12);
            $table->string('title', 128)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('status', 10)->default('untracked');
            $table->string('bot_status', 16)->nullable();
            $table->boolean('bot_is_admin')->default(false);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tracked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tracked_at')->nullable();
            $table->unsignedInteger('seen_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('analysis_due_at')->nullable();
            $table->unsignedSmallInteger('analyses_today')->default(0);
            $table->date('analyses_day')->nullable();
            $table->unsignedBigInteger('last_analysed_message_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index('analysis_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};
