<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §1, §3: the Telegram profile of a connection's owner (first name
 * and @username, cleaned with BotText::clean by UpsertBusinessConnection), so
 * the owner picker can show who tried to connect. Never the phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_connections', function (Blueprint $table): void {
            $table->string('owner_first_name', 64)->nullable()->after('owner_telegram_id');
            $table->string('owner_username', 64)->nullable()->after('owner_first_name');
        });
    }

    public function down(): void
    {
        Schema::table('business_connections', function (Blueprint $table): void {
            $table->dropColumn(['owner_first_name', 'owner_username']);
        });
    }
};
