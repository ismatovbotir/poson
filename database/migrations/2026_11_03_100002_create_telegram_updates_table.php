<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 14 v2: webhook idempotency ledger (update_id inserted first, pruned after 3 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
