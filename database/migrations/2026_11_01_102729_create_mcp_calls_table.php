<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 13 (MCP) §4, C10, C17, C22: audit of every MCP tool call and of
 * denied / rate-limited requests.
 *
 * - arguments: redacted by App\Mcp\Support\Redactor (texts become char
 *   counts; never a token or the Authorization header, C27);
 * - before: snapshot of the edited fields for "View before" (writes, M3+);
 * - status: McpCallStatus (ok / invalid / denied / rate_limited / error).
 * Pruned after 365 days (McpCall is Prunable). (api_token_id, ip) answers
 * "was this IP seen for this token" for the new-IP alert (C10).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auth_type', 10)->default('token');
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('client_name', 100)->nullable();
            $table->string('tool', 64);
            $table->boolean('is_write')->default(false);
            $table->boolean('is_publish')->default(false);
            $table->string('status', 20);
            $table->nullableMorphs('subject');
            $table->json('arguments')->nullable();
            $table->json('before')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['tool', 'created_at']);
            $table->index('created_at');
            $table->index(['api_token_id', 'ip']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
    }
};
