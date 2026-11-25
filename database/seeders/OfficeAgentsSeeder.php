<?php

namespace Database\Seeders;

use App\Actions\Office\InstallAgentProfiles;
use Illuminate\Database\Seeder;

/**
 * The four Virtual office agents with their v1 teaching (plan 41 §5).
 * Create-only and idempotent: profiles that already have a version are
 * never changed. Inert until an Admin switches the office AI on.
 */
class OfficeAgentsSeeder extends Seeder
{
    public function run(): void
    {
        app(InstallAgentProfiles::class)->handle();
    }
}
