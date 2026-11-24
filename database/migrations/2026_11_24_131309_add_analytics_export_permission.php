<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 40 (YD-10): analytics.export (offline-conversion CSV and the ad
 * click id switch) for the Admin role only. Add-only (findOrCreate +
 * givePermissionTo, never sync). Idempotent; also in
 * RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['analytics.export'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = SpatieRole::findOrCreate(Role::Admin->value, RolesAndPermissionsSeeder::GUARD);

        foreach (self::PERMISSIONS as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, RolesAndPermissionsSeeder::GUARD));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', RolesAndPermissionsSeeder::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
