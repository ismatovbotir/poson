<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §4: who a bot user is to the company (TelegramParty client /
 * employee, set by an Admin) and, for an employee, the staff account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->string('party', 10)->nullable();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_user_id');
            $table->dropColumn('party');
        });
    }
};
