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
     * Role name → permission names (plan 03 §3, plan 05 §5).
     *
     * products.manage-services: create/update/delete products of type software
     * and service only (ProductPolicy applies the type rule). products.update
     * etc. cover every product type.
     *
     * CRM (plan 05, decisions 2026-10-22), record rules in Lead / LeadPolicy:
     *  - leads.sales / leads.service: work with sales-type leads / service
     *    requests (own + unassigned); leads.view-all widens that to every lead
     *    of the type (supervisor; Admin only for now).
     *  - leads.service-view: read-only view of all service requests (sales manager).
     *  - leads.assign: assign anyone (Admin). leads.reassign: hand a sales lead
     *    to another sales user (sales manager). Without either: take / release.
     *  - customers.update: own + unassigned customers; customers.update-any: all;
     *    customers.reassign: hand an editable customer to another sales user;
     *    customers.price-level: Admin only.
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

            Role::SalesManager->value => [
                ...$catalogView,
                'leads.viewAny', 'leads.view', 'leads.create', 'leads.update',
                'leads.sales', 'leads.service-view', 'leads.reassign',
                'customers.viewAny', 'customers.view', 'customers.create', 'customers.update', 'customers.reassign',
            ],

            Role::ServiceEngineer->value => [
                ...$catalogView,
                'products.manage-services',
                'leads.viewAny', 'leads.view', 'leads.create', 'leads.update',
                'leads.service',
                'customers.viewAny', 'customers.view',
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
            // Plan 04: admin-only (not granted to any other role in permissionMap()).
            'settings.company',
            'settings.integrations',
            'analytics.view',
            // Plan 05 CRM.
            ...static::abilities(['leads', 'customers'], self::CRUD),
            'leads.sales',
            'leads.service',
            'leads.service-view',
            'leads.view-all',
            'leads.assign',
            'leads.reassign',
            'customers.update-any',
            'customers.reassign',
            'customers.price-level',
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
