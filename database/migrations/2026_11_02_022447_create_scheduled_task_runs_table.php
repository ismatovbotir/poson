<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 17 (monitoring) §1: one row per scheduler run of a task, plus manual
 * "Run now" runs. Written by App\Support\Monitoring\RunRecorder; pruned after
 * 14 days. error is the redacted first line, output the redacted last 2 KB
 * (failed runs only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 191);
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->string('error', 300)->nullable();
            $table->text('output')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task', 'id']);
            $table->index(['status', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
