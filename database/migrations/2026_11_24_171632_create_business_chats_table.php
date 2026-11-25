<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §2: one private chat of a business connection with a counterpart.
 * Unmarked rows are attention stubs (name, username, counters and times
 * only: VO-4); phone is set for client chats only (shared contact or staff
 * entry). The Telegram chat id is a bigint (VO-T1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_connection_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->string('kind', 10)->default('unmarked');
            $table->string('first_name', 64)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('telegram_user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('first_inbound_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_owner_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('analysis_due_at')->nullable();
            $table->unsignedBigInteger('last_analysed_message_id')->nullable();
            $table->unsignedSmallInteger('analyses_today')->default(0);
            $table->date('analyses_day')->nullable();
            $table->timestamps();

            $table->unique(['business_connection_id', 'telegram_chat_id']);
            $table->index(['kind', 'last_inbound_at']);
            $table->index(['assignee_id', 'kind']);
            $table->index('analysis_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_chats');
    }
};
