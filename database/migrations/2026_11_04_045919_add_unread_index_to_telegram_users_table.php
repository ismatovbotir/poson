<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 21 A1: the unread menu badge and the Unread scope filter on
 * unread_count > 0 for every role (admins have no manager filter), so a single
 * index on it serves both; the manager / registered conditions are applied to
 * the few matching rows. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->index('unread_count');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropIndex(['unread_count']);
        });
    }
};
