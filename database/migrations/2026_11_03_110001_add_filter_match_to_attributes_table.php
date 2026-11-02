<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default match mode of a multiselect filter ("all" / "any") and whether
     * visitors get the All | Any switch. Existing rows: all, switch on.
     */
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('filter_match', 3)->default('all')->after('is_filterable');
            $table->boolean('filter_match_switch')->default(true)->after('filter_match');
        });
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropColumn(['filter_match', 'filter_match_switch']);
        });
    }
};
