<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 20 (Telegram Mini App) §6: the Telegram user who placed the order in
 * the Mini App ("My orders"). NULL for every other order. Deleting the
 * Telegram user keeps the order (nullOnDelete, MA-15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('telegram_user_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->index(['telegram_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['telegram_user_id']);
            $table->dropIndex(['telegram_user_id', 'created_at']);
            $table->dropColumn('telegram_user_id');
        });
    }
};
