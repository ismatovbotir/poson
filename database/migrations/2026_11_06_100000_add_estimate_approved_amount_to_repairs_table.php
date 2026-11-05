<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 24 fix batch: the estimate amount (tiyin) the customer approved, so a
 * later change to the estimate cannot ride on an old approval. Costs-gated
 * like the other amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table): void {
            $table->unsignedBigInteger('estimate_approved_amount')->nullable()->after('estimate_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table): void {
            $table->dropColumn('estimate_approved_amount');
        });
    }
};
