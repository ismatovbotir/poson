<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §5: one Virtual office agent per role (router, sales, service,
 * support; rows from LaunchSeeder). current_version_id has no foreign key:
 * PublishAgentProfileVersion checks it belongs to the profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 10)->unique();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('use_site_knowledge')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profiles');
    }
};
