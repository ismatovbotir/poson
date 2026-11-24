<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 37: one measurement of the project folder per target and Tashkent day
 * (the project total, its top-level buckets and the storage detail). A rerun
 * on the same day upserts. Sizes only: never a path or a file name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disk_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('target', 32);
            $table->date('taken_on');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('measured_at')->nullable();

            $table->unique(['target', 'taken_on']);
            $table->index('taken_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disk_snapshots');
    }
};
