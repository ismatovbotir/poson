<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Decision 2026-11-02: system.commands (Admin → System → Commands, read-only
 * list of artisan commands) for the Admin role only. Add-only and idempotent
 * (never syncs); also in RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['system.commands'];

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
