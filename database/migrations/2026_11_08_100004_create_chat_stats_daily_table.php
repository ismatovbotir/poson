<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1: daily counters per channel, metric and rule (0 = no rule).
 * Counts only, never text. No foreign key: DeleteChatRule removes the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('channel', 10);
            $table->string('metric', 20);
            $table->unsignedBigInteger('chat_rule_id')->default(0);
            $table->unsignedInteger('count')->default(0);

            $table->unique(['day', 'channel', 'metric', 'chat_rule_id']);
            $table->index('chat_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_stats_daily');
    }
};
