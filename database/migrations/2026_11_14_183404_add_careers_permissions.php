<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 31: careers.vacancies (vacancies), careers.applications (applications)
 * and careers.cv (CV download) for the Admin role only. Add-only
 * (findOrCreate + givePermissionTo, never sync). Idempotent; also in
 * RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['careers.vacancies', 'careers.applications', 'careers.cv'];

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
