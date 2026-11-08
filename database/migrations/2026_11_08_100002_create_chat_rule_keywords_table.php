<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1: keywords of a chat rule. "normalized" is ChatText::normalize()
 * of "phrase", set on save; matching only uses the normalized form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rule_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_rule_id')->constrained('chat_rules')->cascadeOnDelete();
            $table->char('locale', 2)->nullable();
            $table->string('phrase', 120);
            $table->string('normalized', 120);
            $table->string('match_type', 10);
            $table->unsignedTinyInteger('weight')->default(10);
            $table->timestamps();

            $table->unique(['chat_rule_id', 'match_type', 'normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_rule_keywords');
    }
};
