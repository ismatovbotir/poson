<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the one-time intro was sent at the phone (Contact) step of the bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->timestamp('contact_intro_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn('contact_intro_sent_at');
        });
    }
};
