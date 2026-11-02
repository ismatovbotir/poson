<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 17 (monitoring), MON-S10: monitoring.view / monitoring.manage for the
 * Admin role only. A data migration so production gets them without
 * re-running LaunchSeeder (which would re-run the demo seeders). Idempotent.
 * The permissions are also in RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['monitoring.view', 'monitoring.manage'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = SpatieRole::findOrCreate(Role::Admin->value, RolesAndPermissionsSeeder::GUARD);

        foreach (self::PERMISSIONS as $name) {
            $admin->givePermissionTo(Permission::findOrCreate($name, RolesAndPermissionsSeeder::GUARD));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', RolesAndPermissionsSeeder::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
