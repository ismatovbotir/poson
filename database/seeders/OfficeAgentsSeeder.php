<?php

namespace Database\Seeders;

use App\Actions\Office\InstallAgentGuidance;
use App\Actions\Office\InstallAgentProfiles;
use Illuminate\Database\Seeder;

/**
 * The four Virtual office agents with their v1 teaching (plan 41 §5).
 * Create-only and idempotent: profiles that already have a version are
 * never changed; the guidance versions (plan 46) are unpublished. Inert until an Admin switches the office AI on.
 */
class OfficeAgentsSeeder extends Seeder
{
    public function run(): void
    {
        app(InstallAgentProfiles::class)->handle();
        // Plan 46: an unpublished version with the default guidance per role (never published here).
        app(InstallAgentGuidance::class)->handle();
    }
}
