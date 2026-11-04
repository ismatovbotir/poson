<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 14 v2 B4 (condition B4-S3): the team chat the current media header
 * was sent to. When the inbox target changes (inbox ↔ sales chat), the next
 * file gets a new header in the new chat instead of replying to a header
 * that lives in another chat. Additive, nullable, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->bigInteger('forward_header_chat_id')->nullable()->after('forward_header_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn('forward_header_chat_id');
        });
    }
};
