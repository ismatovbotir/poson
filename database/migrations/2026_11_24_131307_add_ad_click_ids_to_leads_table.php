<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 40 (YD-7, YD-8): the Yandex Direct click id (yclid, digits only, at
 * most 32) and the Metrika ClientID (_ym_uid, 10–24 digits) of the visit that
 * created the row. Written only while the Admin switch
 * analytics.ad_click_ids_enabled is on; nulled after 12 months by
 * analytics:prune-click-ids. Never shown raw, logged or sent anywhere but
 * the offline-conversion CSV. No indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('yclid', 32)->nullable()->after('utm');
            $table->string('ym_client_id', 24)->nullable()->after('yclid');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['yclid', 'ym_client_id']);
        });
    }
};
