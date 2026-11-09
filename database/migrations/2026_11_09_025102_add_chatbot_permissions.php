<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 28 C2: chatbot.rules (rules, teach, console, stats) and chatbot.settings
 * (texts, hours, hand-off) for Admin and Content manager. Turning a channel on
 * is Admin only and is checked in the action, not by a permission. Add-only
 * (findOrCreate + givePermissionTo, never sync) so manual role edits in
 * production survive. Idempotent; also in RolesAndPermissionsSeeder for fresh installs.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['chatbot.rules', 'chatbot.settings'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([Role::Admin->value, Role::ContentManager->value] as $roleName) {
            $role = SpatieRole::findOrCreate($roleName, RolesAndPermissionsSeeder::GUARD);

            foreach (self::PERMISSIONS as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, RolesAndPermissionsSeeder::GUARD));
            }
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
