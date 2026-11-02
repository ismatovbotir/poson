<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 17 (monitoring) §1, MON-S9: audit of admin actions on the monitoring
 * screen (run_task / retry_job / forget_job). target is a TEXT column: one
 * task key, or the JSON list of up to 50 failed-job uuids (never payloads).
 * Pruned after 365 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->text('target');
            $table->string('job_class', 191)->nullable();
            $table->unsignedSmallInteger('count')->default(1);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_actions');
    }
};
