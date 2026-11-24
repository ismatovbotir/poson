<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 38: an optional display colour per select / multiselect option
 * ("#RRGGBB", validated by App\Support\HexColor on write and on read).
 * Used by the ribbon colour (roll diagram, filter swatch); generic for any option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->char('hex_color', 7)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->dropColumn('hex_color');
        });
    }
};
