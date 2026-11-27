<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 46 §3: the escalation result of a group thread and the agent version
 * that wrote it (feedback and metrics).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_group_threads', function (Blueprint $table): void {
            $table->boolean('needs_human')->default(false);
            $table->string('escalation', 24)->nullable();
            $table->string('draft_hold', 16)->nullable();
            $table->foreignId('agent_version_id')->nullable()->constrained('agent_profile_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_group_threads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_version_id');
            $table->dropColumn(['needs_human', 'escalation', 'draft_hold']);
        });
    }
};
