<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C5 §19.2 (CB-18, CB-25, WC-5, WC-14, WC-15, WC-17): one website
 * chat. The visitor is known only by the sha256 of the random cookie token
 * (token_hash, never the token), a daily-salted HMAC of the IP (ip_hash,
 * nulled after 30 days) and the page the chat started on (first_page, path
 * only). name / phone are what the visitor typed (never verified, never
 * matched to a customer: customer_id is set by staff only, WC-9).
 *
 * Plan 33 B hooks: ai_context_at (start of the AI context) and dialog.ai.
 * Retention (chat:prune, C5d): ages from last_message_at; a banned chat has
 * its messages and name, phone, first_page and dialog purged 30 days after
 * banned_at (purged_at records that) and the row deleted after 12 months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_chats', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('locale', 2);
            $table->string('name', 64)->nullable();
            $table->string('phone', 16)->nullable();

            $table->timestamp('consent_at')->nullable();
            $table->string('consent_version', 20)->nullable();

            $table->string('mode', 10)->default('bot');
            $table->json('dialog')->nullable();
            $table->string('conversation_status', 10)->default('open');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('ai_context_at')->nullable();

            $table->char('ip_hash', 64)->nullable();
            $table->string('first_page', 255)->nullable();

            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('banned_at')->nullable();
            $table->foreignId('banned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'conversation_status', 'last_message_at']);
            $table->index(['manager_id', 'conversation_status']);
            $table->index(['handoff_at', 'last_message_at']);
            $table->index('last_message_at');
            $table->index(['ip_hash', 'created_at']);
            $table->index(['ip_hash', 'banned_at']);
            $table->index('banned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_chats');
    }
};
