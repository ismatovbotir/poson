<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 24 B1: repairs.* permissions. Add-only (findOrCreate + givePermissionTo,
 * never sync) so manual role edits in production survive. Idempotent; also in
 * RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    private const ADMIN = ['repairs.viewAny', 'repairs.view', 'repairs.create', 'repairs.update', 'repairs.assign', 'repairs.view-all', 'repairs.costs'];

    private const SALES_MANAGER = ['repairs.viewAny', 'repairs.view', 'repairs.view-all', 'repairs.costs'];

    private const SERVICE_ENGINEER = ['repairs.viewAny', 'repairs.view', 'repairs.create', 'repairs.update', 'repairs.costs'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $grants = [
            Role::Admin->value => self::ADMIN,
            Role::SalesManager->value => self::SALES_MANAGER,
            Role::ServiceEngineer->value => self::SERVICE_ENGINEER,
        ];

        foreach ($grants as $roleName => $names) {
            $role = SpatieRole::findOrCreate($roleName, RolesAndPermissionsSeeder::GUARD);

            foreach ($names as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, RolesAndPermissionsSeeder::GUARD));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::ADMIN)->where('guard_name', RolesAndPermissionsSeeder::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
