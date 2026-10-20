<?php

namespace Database\Seeders;

use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles and their permissions. Idempotent: safe to run on every deploy.
 *
 * permissionMap() is the single source of truth. Each role's permissions are
 * synced to it, so removing a permission from the map also revokes it.
 * Permissions no longer in the map are not deleted (they may still be
 * referenced); remove them with a migration when needed.
 *
 * Admin gets every permission here, and Gate::before also allows admins
 * everything (AppServiceProvider).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    /**
     * Standard abilities of a resource.
     */
    public const CRUD = ['viewAny', 'view', 'create', 'update', 'delete'];

    public const VIEW = ['viewAny', 'view'];

    /**
     * Seed the roles and permissions.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (static::allPermissions() as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        // Model events (which flush this cache) are off under WithoutModelEvents.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (static::permissionMap() as $roleName => $permissions) {
            SpatieRole::findOrCreate($roleName, self::GUARD)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Role name → permission names (plan 03 §3).
     *
     * products.manage-services: create/update/delete products of type software
     * and service only (ProductPolicy applies the type rule). products.update
     * etc. cover every product type.
     *
     * @return array<string, list<string>>
     */
    public static function permissionMap(): array
    {
        $catalog = ['categories', 'attributes', 'brands', 'products'];

        $catalogView = static::abilities($catalog, self::VIEW);

        return [
            Role::Admin->value => static::allPermissions(),

            Role::ContentManager->value => [
                ...static::abilities($catalog, self::CRUD),
                'products.manage-services',
            ],

            Role::SalesManager->value => $catalogView,

            Role::ServiceEngineer->value => [
                ...$catalogView,
                'products.manage-services',
            ],
        ];
    }

    /**
     * Every permission the application knows.
     *
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [
            ...static::abilities(['categories', 'attributes', 'brands', 'products'], self::CRUD),
            'products.manage-services',
            'products.force-delete',
        ];
    }

    /**
     * @param  list<string>  $resources
     * @param  list<string>  $abilities
     * @return list<string>
     */
    protected static function abilities(array $resources, array $abilities): array
    {
        $names = [];

        foreach ($resources as $resource) {
            foreach ($abilities as $ability) {
                $names[] = "{$resource}.{$ability}";
            }
        }

        return $names;
    }
}
