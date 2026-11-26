<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §3: one analysis run of a tracked group, columns as in
 * business_chat_analyses. summary, next_step and draft_reply are encrypted at
 * rest (OG-11). member_ids: the members whose messages the run used
 * (Forget targets only those runs; null = unknown, always nulled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);
            $table->string('trigger', 8);
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('up_to_message_id')->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->string('role', 10)->nullable();
            $table->string('topic', 120)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('language', 2)->nullable();
            $table->text('summary')->nullable();
            $table->text('next_step')->nullable();
            $table->text('draft_reply')->nullable();
            $table->boolean('draft_blocked')->default(false);
            $table->json('lead_fields')->nullable();
            // The group member ids whose messages this run used (M2: Forget nulls only the runs that included a member).
            $table->json('member_ids')->nullable();
            $table->string('lead_action', 12)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('router_version_id')->nullable();
            $table->foreignId('agent_version_id')->nullable()->constrained('agent_profile_versions')->nullOnDelete();
            $table->json('models')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->string('error', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['telegram_group_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_analyses');
    }
};
