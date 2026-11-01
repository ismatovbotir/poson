<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 13 (MCP) §4 + scope update 2026-11-01: the single MCP holder (the
 * CEO, Admin role). Set only by App\Actions\Mcp\GrantMcpAccess (at most one
 * user at a time, checked under a lock there and on every MCP request);
 * cleared by RevokeMcpAccess. Not fillable.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mcp_access_granted_at')->nullable()->index();
            $table->foreignId('mcp_access_granted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mcp_access_granted_by');
            $table->dropIndex(['mcp_access_granted_at']);
            $table->dropColumn('mcp_access_granted_at');
        });
    }
};
