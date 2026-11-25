<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 20 M5 (§6.4, MA-14, MA-25): a customer order status message is a
 * system row of the conversation (user_id null) tied to its order and the
 * status it announces. The unique key makes "one message per (order,
 * to-status)" a database rule: the writer uses insertOrIgnore, never
 * check-then-insert. Deleting the order keeps the message in the thread
 * (nullOnDelete); NULLs never collide in the unique key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->string('order_status', 10)->nullable()->after('order_id');
            $table->unique(['order_id', 'order_status'], 'telegram_messages_order_status_unique');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropUnique('telegram_messages_order_status_unique');
            $table->dropColumn(['order_id', 'order_status']);
        });
    }
};
