<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One price batch received on POST /api/v1/prices (plan 09), rejected
 * batches of an authenticated token included. Append-only (no updated_at),
 * pruned after PriceSyncLog::KEEP_DAYS days.
 *
 * - token_name: snapshot, so the history reads well after a token is gone.
 * - results: one entry per received item (product_id, code, status,
 *   errors, old / new tiyin of the three levels for updated items): the
 *   price history.
 * - error: machine code of a rejected batch.
 *
 * Dated before create_products_table: products.price_sync_log_id references it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_name', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('unknown')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->json('unknown_codes')->nullable();
            $table->json('results')->nullable();
            $table->string('error', 50)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['api_token_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_sync_logs');
    }
};
