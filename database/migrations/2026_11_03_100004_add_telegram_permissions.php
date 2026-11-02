<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 14 v2 (TG-34): telegram.* permissions. Add-only (findOrCreate +
 * givePermissionTo, never sync) so manual role edits in production survive.
 * Idempotent; also in RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    private const ADMIN = ['telegram.inbox', 'telegram.inbox-all', 'telegram.reassign', 'telegram.ban', 'telegram.bot'];

    private const SALES_MANAGER = ['telegram.inbox', 'telegram.reassign', 'telegram.ban'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([Role::Admin->value => self::ADMIN, Role::SalesManager->value => self::SALES_MANAGER] as $roleName => $names) {
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
