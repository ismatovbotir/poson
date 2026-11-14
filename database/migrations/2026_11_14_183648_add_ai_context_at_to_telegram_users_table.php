<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 30 §3, §7: where the AI history of a Telegram conversation starts.
 * Set on Menu, /start, cancel, hand-off and a staff reply; turns before it
 * stay stored (staff still see them) but are no longer sent to the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->timestamp('ai_context_at')->nullable()->after('last_staff_reply_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn('ai_context_at');
        });
    }
};
