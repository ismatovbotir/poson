<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 17 (monitoring) §1: heartbeats (heartbeat:scheduler, heartbeat:worker),
 * the schedule snapshot and the open-problem / alert state. Lives in the DB so
 * it doesn't depend on the cache store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_states', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->timestamp('ticked_at')->nullable();
            $table->timestamp('problem_since')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_states');
    }
};
