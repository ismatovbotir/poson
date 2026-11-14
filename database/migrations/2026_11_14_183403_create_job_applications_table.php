<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 31: applications to vacancies. The CV itself is never stored here:
 * only Telegram's file_id after the forward (and, for at most 24 hours, the
 * path of an encrypted pending copy). No IP, no UTM. Kept until deleted by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            // Null = an open application to a department (no vacancy listed).
            $table->foreignId('vacancy_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('department', 20);
            $table->string('status', 20)->default('new');
            $table->string('name', 120);
            $table->string('phone', 16);
            $table->string('email', 190)->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 5);
            $table->string('cv_status', 20)->default('none');
            $table->string('cv_pending_path', 255)->nullable();
            $table->string('cv_file_id', 255)->nullable();
            $table->string('cv_file_unique_id', 64)->nullable();
            $table->string('cv_original_name', 150)->nullable();
            $table->string('cv_mime', 100)->nullable();
            $table->unsignedInteger('cv_size')->nullable();
            $table->timestamp('cv_forwarded_at')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['vacancy_id', 'status']);
            $table->index(['department', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['cv_status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
