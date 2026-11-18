<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 36: a service request carries the device (brand, model, serial) and
 * how the client wants to be reached. The problem is the lead's message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('device_brand', 80)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('serial_number', 64)->nullable();
            $table->string('preferred_contact', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['device_brand', 'device_model', 'serial_number', 'preferred_contact']);
        });
    }
};
