<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §2-3: one conversation thread found in a group (per client member,
 * or the whole group for a customer-linked group). summary, next_step and
 * draft are encrypted at rest (OG-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_member_id')->nullable()->constrained('telegram_group_members')->nullOnDelete();
            $table->string('role', 10)->nullable();
            $table->string('topic', 120)->nullable();
            $table->text('summary')->nullable();
            $table->text('next_step')->nullable();
            $table->text('draft')->nullable();
            $table->boolean('draft_blocked')->default(false);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // The group member ids whose messages the last analysis of this thread used (M2, Forget).
            $table->json('member_ids')->nullable();
            $table->foreignId('last_analysis_id')->nullable()->constrained('telegram_group_analyses')->nullOnDelete();
            $table->timestamps();

            $table->index(['telegram_group_id', 'client_member_id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_threads');
    }
};
