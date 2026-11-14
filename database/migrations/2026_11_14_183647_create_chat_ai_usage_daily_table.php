<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 30 §3, AI-18: AI usage per Asia/Tashkent day and channel. Counts and
 * tokens only, never text. "requests" and "reserved_tokens" are reserved
 * atomically before each API call (UPDATE ... WHERE requests < cap), the
 * reservation is settled into "billed_tokens" (input + output + cache write
 * + 0.1 x cache read) afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('channel', 10);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('answered')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->unsignedInteger('capped')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('billed_tokens')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);

            $table->unique(['day', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_usage_daily');
    }
};
