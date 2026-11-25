<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 41 §7: Virtual office permissions. office.chats and office.analyse
 * for the sales manager and the service engineer, office.assign (own chats
 * to another office user) for the sales manager; chats-all, mark,
 * education and settings stay Admin only (Gate::before). Add-only
 * (findOrCreate + givePermissionTo, never sync). Idempotent; also in
 * RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    /**
     * Inlined (a migration must not change when the seeder constants change later).
     */
    private const PERMISSIONS = ['office.chats', 'office.chats-all', 'office.analyse', 'office.mark', 'office.assign', 'office.education', 'office.settings'];

    private const SALES = ['office.chats', 'office.analyse', 'office.assign'];

    private const ENGINEER = ['office.chats', 'office.analyse'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, RolesAndPermissionsSeeder::GUARD);
        }

        $grants = [
            Role::SalesManager->value => self::SALES,
            Role::ServiceEngineer->value => self::ENGINEER,
        ];

        foreach ($grants as $roleName => $permissions) {
            SpatieRole::findOrCreate($roleName, RolesAndPermissionsSeeder::GUARD)->givePermissionTo($permissions);
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
