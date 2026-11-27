<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 46 §2.1: the structured guidance of an agent version (tone, must-ask,
 * escalations, service facts, knowledge sources, forbidden topics, confidence
 * floor, routing hints). Null on legacy v1 versions (free text only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profile_versions', function (Blueprint $table): void {
            $table->json('guidance')->nullable()->after('examples');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profile_versions', function (Blueprint $table): void {
            $table->dropColumn('guidance');
        });
    }
};
