<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 20 (Telegram Mini App) §5: a Mini App cart belongs to one Telegram
 * user (no cookie, the token is never rendered). Site carts keep NULL.
 * Deleting the Telegram user deletes the carts (their lines cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('telegram_user_id')->nullable()->after('token')->constrained()->cascadeOnDelete();
            $table->index(['telegram_user_id', 'converted_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['telegram_user_id']);
            $table->dropIndex(['telegram_user_id', 'converted_order_id']);
            $table->dropColumn('telegram_user_id');
        });
    }
};
