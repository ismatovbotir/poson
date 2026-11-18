<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan 35: the unguessable token behind the receipt QR. Binary collation
     * (case-sensitive match) on MySQL; sqlite ignores it, so the controller also
     * compares with hash_equals.
     */
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $token = $table->string('public_token', 40)->nullable()->after('closed_at');

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $token->charset('ascii')->collation('ascii_bin');
            }

            $token->unique();
            $table->timestamp('public_token_rotated_at')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'public_token_rotated_at']);
        });
    }
};
