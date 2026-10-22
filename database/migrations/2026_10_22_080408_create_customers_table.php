<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM customers (plan 05 §Schema; screens in 5b). The phone is normalized
 * to +998XXXXXXXXX (indexed for the lead phone match); the INN (9 digits)
 * is unique when present. price_level (Admin only) picks the price column
 * for admin quotes and orders. The manager is unset when the user is deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('company');
            $table->string('name');
            $table->string('inn', 9)->nullable()->unique();
            $table->string('phone', 16)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('price_level', 20)->default('retail');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('locale', 5)->default('uz');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
