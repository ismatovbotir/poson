<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 30 §16 / §18 (AI-B15): an answered AI turn is linked to the
 * unmatched phrase of its question, with the reply locale, so the owner can
 * teach a rule from the answer (Chat bot → Teach). The link goes when the
 * phrase is deleted (nullOnDelete); deleting the conversation deletes the
 * turns and keeps the phrase. Indexed for "AI-answered N times".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_ai_turns', function (Blueprint $table): void {
            $table->foreignId('chat_unmatched_id')->nullable()->after('answers_turn_id')->constrained('chat_unmatched')->nullOnDelete();
            $table->char('locale', 2)->nullable()->after('chat_unmatched_id');
            $table->index(['chat_unmatched_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_ai_turns', function (Blueprint $table): void {
            $table->dropIndex(['chat_unmatched_id', 'status']);
            $table->dropConstrainedForeignId('chat_unmatched_id');
            $table->dropColumn('locale');
        });
    }
};
