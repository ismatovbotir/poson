<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens of the machine API (plan 09, no Sanctum). The plain token
 * ("pos_" + 48 random characters) is shown once; only its sha256 is kept,
 * plus the first 12 characters for a masked display. Abilities are a json
 * list (default ["prices.write"]). Revoked / expired tokens are kept for
 * the sync log history; the creator link survives user deletion as null.
 *
 * Dated before create_products_table, like price_sync_logs (which references it).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->json('abilities');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
