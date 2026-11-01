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
     * Content resources (plan 07): Content manager and Admin only.
     */
    public const CONTENT = ['pages', 'news', 'banners'];

    /**
     * Orders (plan 11 v2 §8): what the sales manager may do; Admin also refunds.
     */
    public const ORDERS_SALES = ['orders.viewAny', 'orders.view', 'orders.create', 'orders.update', 'orders.mark-paid'];

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
     *  - quotes.*: own quotes and quotes of customers the user manages
     *    (quotes.view-all: every quote, Admin). The service engineer has no
     *    quote access (plan 06, safest option).
     *    customers.price-level: Admin only.
     *  - pages.* / news.* / banners.*: Content manager + Admin (plan 07).
     *  - api-tokens.manage / price-sync.view: price API tokens and the sync
     *    log (plan 09); Admin only, no other role.
     *  - orders.* (plan 11b): sales manager views, creates, edits every real
     *    order and marks manual payments paid; the service engineer only
     *    views (no payment details); orders.refund, orders.price-override
     *    (manual order prices below the floor, free-text lines), payments.void (void a
     *    manual payment), payments.reconcile, payments.test (TEST orders)
     *    and settings.checkout are Admin only. payments.viewAny / view:
     *    Admin + sales manager.
     *  - mcp.manage (plan 13): Settings → MCP access and the MCP call log;
     *    Admin only.
     *
     * @return array<string, list<string>>
     */
    public static function permissionMap(): array
    {
        // roll-types (plan 08): label roll types, same matrix as the rest of the catalog.
        $catalog = ['categories', 'attributes', 'brands', 'products', 'roll-types'];

        $catalogView = static::abilities($catalog, self::VIEW);

        return [
            Role::Admin->value => static::allPermissions(),

            Role::ContentManager->value => [
                ...static::abilities($catalog, self::CRUD),
                ...static::abilities(self::CONTENT, self::CRUD),
                'products.manage-services',
            ],

            Role::SalesManager->value => [
                ...$catalogView,
                'leads.viewAny', 'leads.view', 'leads.create', 'leads.update',
                'leads.sales', 'leads.service-view', 'leads.reassign',
                'customers.viewAny', 'customers.view', 'customers.create', 'customers.update', 'customers.reassign',
                ...static::abilities(['quotes'], self::CRUD),
                ...self::ORDERS_SALES,
                'payments.viewAny', 'payments.view',
            ],

            Role::ServiceEngineer->value => [
                ...$catalogView,
                'products.manage-services',
                'leads.viewAny', 'leads.view', 'leads.create', 'leads.update',
                'leads.service',
                'customers.viewAny', 'customers.view',
                'orders.viewAny', 'orders.view',
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
            ...static::abilities(['categories', 'attributes', 'brands', 'products', 'roll-types'], self::CRUD),
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
            // Plan 06 quotes (quotes.view-all: Admin only).
            ...static::abilities(['quotes'], self::CRUD),
            'quotes.view-all',
            // Plan 07 content: pages (7a), news (7b), banners (7c).
            ...static::abilities(self::CONTENT, self::CRUD),
            // Plan 09 price API: tokens (Settings → API tokens) and the sync log. Admin only.
            'api-tokens.manage',
            'price-sync.view',
            // Plan 11b orders and payments (orders.refund, orders.price-override,
            // payments.test / void / reconcile and settings.checkout: Admin only).
            ...self::ORDERS_SALES,
            'orders.refund',
            'orders.price-override',
            'payments.viewAny',
            'payments.view',
            'payments.test',
            'payments.void',
            'payments.reconcile',
            'settings.checkout',
            // Plan 13: Settings → MCP access (single CEO holder, tokens, call log). Admin only.
            'mcp.manage',
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
