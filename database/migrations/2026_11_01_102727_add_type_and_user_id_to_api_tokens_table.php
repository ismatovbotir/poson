<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 13 (MCP), delta security condition C3: api_tokens also holds the
 * CEO's MCP tokens.
 *
 * - type: "price_api" (plan 09, every existing row) or "mcp" (ApiTokenType);
 *   lookups always filter by it (C5);
 * - user_id: the MCP holder the token acts as (required for MCP rows,
 *   enforced by GenerateMcpToken; null for price API tokens);
 * - last_used_ua: short User-Agent of the last use (new-UA alerts, C10);
 * - allowed_ips: optional CIDR allow-list (≤ 10, C14);
 * - idle_revoke_days: per-token idle auto-revoke (null = 45 days, C12);
 * - revoked_reason: why it was revoked (McpRevokeReason, ≤ 32 chars).
 * The existing name column is the device label (C9).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('type', 20)->default('price_api')->after('id')->index();
            $table->foreignId('user_id')->nullable()->after('type')->constrained('users')->cascadeOnDelete();
            $table->string('last_used_ua', 255)->nullable()->after('last_used_ip');
            $table->json('allowed_ips')->nullable()->after('abilities');
            $table->unsignedSmallInteger('idle_revoke_days')->nullable()->after('allowed_ips');
            $table->string('revoked_reason', 32)->nullable()->after('revoked_at');
            $table->index(['user_id', 'type', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'revoked_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'last_used_ua', 'allowed_ips', 'idle_revoke_days', 'revoked_reason']);
        });
    }
};
