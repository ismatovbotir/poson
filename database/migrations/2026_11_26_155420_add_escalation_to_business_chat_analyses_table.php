<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 46 §3: the escalation result of an analysis (needs a human, which
 * trigger, why a draft was held) and an index for the per-version metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_chat_analyses', function (Blueprint $table): void {
            $table->boolean('needs_human')->default(false);
            $table->string('escalation', 24)->nullable();
            $table->string('draft_hold', 16)->nullable();

            $table->index(['agent_version_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('business_chat_analyses', function (Blueprint $table): void {
            $table->dropIndex(['agent_version_id', 'created_at']);
            $table->dropColumn(['needs_human', 'escalation', 'draft_hold']);
        });
    }
};
