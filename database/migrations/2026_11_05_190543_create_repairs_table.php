<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 24 B1: a device brought in for service. Plain text columns (no
 * translations, no soft deletes: a repair is cancelled, never deleted). Money
 * in tiyin. Customer, contact and lead links are optional and nulled when the
 * linked row goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repairs', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('status', 20)->default('received');
            $table->string('locale', 5)->default('uz');

            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name', 120);
            $table->string('contact_phone', 16);
            $table->string('company', 160)->nullable();

            $table->string('device_type', 20);
            $table->string('device_brand', 80)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('serial_number', 64)->nullable();
            $table->string('accessories', 500)->nullable();
            $table->text('intake_condition');
            $table->text('problem');
            $table->text('diagnosis')->nullable();
            $table->text('work_done')->nullable();
            $table->text('parts_used')->nullable();

            $table->string('coverage', 20)->default('paid');
            $table->string('warranty_reference', 120)->nullable();
            $table->unsignedBigInteger('estimate_amount')->nullable();
            $table->unsignedBigInteger('final_amount')->nullable();
            $table->timestamp('estimate_approved_at')->nullable();

            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to_id', 'status']);
            $table->index('contact_phone');
            $table->index('serial_number');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repairs');
    }
};
