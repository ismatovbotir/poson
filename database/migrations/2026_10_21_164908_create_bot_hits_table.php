<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw crawler requests (plan 04 4c), kept 14 days. bot = key from
 * config/ai-bots.php (what the User-Agent claims). ip is the packed address
 * (inet_pton, 4 or 16 bytes: varbinary(16) on MySQL, blob on SQLite), kept
 * for the nightly verification only. verified: null = not checked / not
 * checkable, true = genuine, false = spoofed User-Agent. No foreign keys.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_hits', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('bot', 40);
            $table->binary('ip', 16)->nullable();
            $table->boolean('verified')->nullable();
            $table->string('route_name', 100)->nullable();
            $table->string('path', 255);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedSmallInteger('status')->default(200);

            $table->index('occurred_at');
            $table->index(['bot', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_hits');
    }
};
