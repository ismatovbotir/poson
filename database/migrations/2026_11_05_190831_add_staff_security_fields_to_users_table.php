<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 24 A1: session invalidation and forced password change. Additive, and
 * a session without a stamped epoch reads as 0, so nobody is signed out when
 * this runs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
            $table->timestamp('temp_password_expires_at')->nullable()->after('session_epoch');
            $table->timestamp('password_changed_at')->nullable()->after('temp_password_expires_at');
            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['session_epoch', 'temp_password_expires_at', 'password_changed_at', 'last_login_at']);
        });
    }
};
