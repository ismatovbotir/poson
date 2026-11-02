<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 14 v2 (TG-32): customers.telegram_id was never written by any code
 * path; Telegram identities now live in telegram_users. Guarded: refuses to
 * run when any row has data (resolve manually first). Last migration of the
 * Plan 14 batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'telegram_id')) {
            return;
        }

        if (DB::table('customers')->whereNotNull('telegram_id')->exists()) {
            throw new RuntimeException('customers.telegram_id has data; resolve manually before migrating.');
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_telegram_id_unique');
            $table->dropColumn('telegram_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'telegram_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('manager_id');
        });
    }
};
