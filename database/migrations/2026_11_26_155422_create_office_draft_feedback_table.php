<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 46 §2.2 (ED-3, ED46-8): what staff did with an AI draft (used, edited,
 * rejected). note, draft_text and final_text are redacted and encrypted at
 * rest (TEXT: ciphertext is longer than the plain text) and nulled by
 * ForgetDraftFeedback / the prune; the count-only row stays for metrics.
 * Deleting the chat or the group deletes its feedback (cascade). Exactly one
 * of chat / group is set (enforced by the action).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_draft_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_chat_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_chat_analysis_id')->nullable()->constrained('business_chat_analyses')->nullOnDelete();
            $table->foreignId('telegram_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_group_thread_id')->nullable()->constrained('telegram_group_threads')->nullOnDelete();
            $table->foreignId('telegram_group_analysis_id')->nullable()->constrained('telegram_group_analyses')->nullOnDelete();
            $table->foreignId('agent_version_id')->nullable()->constrained('agent_profile_versions')->nullOnDelete();
            $table->string('role', 10);
            $table->string('outcome', 10);
            $table->string('reason', 16)->nullable();
            $table->text('note')->nullable();
            $table->text('draft_text')->nullable();
            $table->text('final_text')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('promoted_version_id')->nullable()->constrained('agent_profile_versions')->nullOnDelete();
            $table->foreignId('promoted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->unique('business_chat_analysis_id');
            $table->unique(['telegram_group_thread_id', 'telegram_group_analysis_id'], 'office_feedback_thread_analysis_unique');
            $table->index(['outcome', 'created_at']);
            $table->index(['agent_version_id', 'outcome']);
            $table->index('created_at');
            // The retention sweep (ForgetDraftFeedback::prune) reads by the last update.
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_draft_feedback');
    }
};
