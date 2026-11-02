<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 14 v2: one row per private Telegram chat with the bot (the registered
 * customer or an anonymous visitor), plus its inbox state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->string('last_name', 128)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('state', 20)->default('language');

            $table->string('phone', 16)->nullable()->index();
            $table->string('name', 120)->nullable();
            $table->string('declared_company', 160)->nullable();
            $table->string('declared_inn', 9)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('registered_at')->nullable();

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('link_verified_at')->nullable();
            $table->foreignId('link_verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('account_changed')->default(false);

            $table->string('conversation_status', 10)->default('open');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->unsignedBigInteger('forward_header_message_id')->nullable();
            $table->timestamp('forward_header_at')->nullable();

            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->foreignId('banned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_status', 'last_message_at']);
            $table->index(['manager_id', 'conversation_status']);
            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
