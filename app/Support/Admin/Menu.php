<?php

namespace App\Support\Admin;

use App\Enums\Role;
use App\Models\User;

/**
 * Single source of truth for the admin navigation (sidebar, drawer and the
 * bottom tab bar in layouts/admin.blade.php).
 *
 * The full structure is always declared. Items whose route doesn't exist yet
 * render as "soon" and light up automatically once the module registers its
 * route. Visibility uses the item's permission (through the Gate, so admins
 * pass via Gate::before) or, for modules without permissions yet, its role list.
 */
final class Menu
{
    /**
     * The complete menu, unfiltered.
     *
     * @return list<MenuGroup>
     */
    public static function definition(): array
    {
        // Role lists follow the roles-permissions skill matrix until each module
        // has its permissions: then the item switches to permission: '<resource>.viewAny'
        // (as the catalog items already do) and the role list goes away.
        $sales = [Role::Admin, Role::SalesManager];
        $service = [Role::Admin, Role::ServiceEngineer];
        $content = [Role::Admin, Role::ContentManager];
        $admin = [Role::Admin];

        // Matrix: service engineer has service_request leads (own), customers (view),
        // service quotes (own) and orders (view); the sales manager views payments
        // and service requests.
        $crm = [Role::Admin, Role::SalesManager, Role::ServiceEngineer];
        $payments = [Role::Admin, Role::SalesManager];
        $serviceRequests = [Role::Admin, Role::ServiceEngineer, Role::SalesManager];

        return [
            new MenuGroup('dashboard', 'menu.groups.dashboard', [
                new MenuItem('dashboard', 'menu.items.dashboard', 'dashboard', 'admin.dashboard'),
            ], collapsible: false),

            new MenuGroup('sales', 'menu.groups.sales', [
                new MenuItem('leads', 'menu.items.leads', 'inbox', 'admin.leads.index', roles: $crm),
                new MenuItem('customers', 'menu.items.customers', 'building', 'admin.customers.index', roles: $crm),
                new MenuItem('quotes', 'menu.items.quotes', 'file-text', 'admin.quotes.index', roles: $crm),
                new MenuItem('orders', 'menu.items.orders', 'bag', 'admin.orders.index', roles: $crm),
                new MenuItem('payments', 'menu.items.payments', 'card', 'admin.payments.index', roles: $payments),
            ]),

            new MenuGroup('catalog', 'menu.groups.catalog', [
                new MenuItem('categories', 'menu.items.categories', 'folder', 'admin.categories.index', permission: 'categories.viewAny'),
                new MenuItem('attributes', 'menu.items.attributes', 'sliders', 'admin.attributes.index', permission: 'attributes.viewAny'),
                new MenuItem('brands', 'menu.items.brands', 'tag', 'admin.brands.index', permission: 'brands.viewAny'),
                new MenuItem('products', 'menu.items.products', 'box', 'admin.products.index', permission: 'products.viewAny'),
                new MenuItem('price-import', 'menu.items.price_import', 'upload', 'admin.price-import.index', roles: $content),
            ]),

            new MenuGroup('service', 'menu.groups.service', [
                new MenuItem('service-requests', 'menu.items.service_requests', 'wrench', 'admin.service-requests.index', roles: $serviceRequests),
                new MenuItem('repairs', 'menu.items.repairs', 'tool', 'admin.repairs.index', roles: $service),
            ]),

            new MenuGroup('content', 'menu.groups.content', [
                new MenuItem('pages', 'menu.items.pages', 'page', 'admin.pages.index', roles: $content),
                new MenuItem('news', 'menu.items.news', 'news', 'admin.news.index', roles: $content),
                new MenuItem('banners', 'menu.items.banners', 'image', 'admin.banners.index', roles: $content),
            ]),

            new MenuGroup('telegram', 'menu.groups.telegram', [
                new MenuItem('telegram-bot', 'menu.items.telegram_bot', 'bot', 'admin.telegram.bot', roles: $admin),
                new MenuItem('telegram-mini-app', 'menu.items.telegram_mini_app', 'smartphone', 'admin.telegram.mini-app', roles: $admin),
                new MenuItem('telegram-chats', 'menu.items.telegram_chats', 'message', 'admin.telegram.chats', roles: $sales),
            ]),

            new MenuGroup('ai', 'menu.groups.ai', [
                new MenuItem('ai-usage', 'menu.items.ai_usage', 'sparkles', 'admin.ai.usage', roles: $admin),
            ]),

            new MenuGroup('settings', 'menu.groups.settings', [
                new MenuItem('company', 'menu.items.company', 'briefcase', 'admin.settings.company', permission: 'settings.company'),
                new MenuItem('users', 'menu.items.users', 'users', 'admin.users.index', roles: $admin),
                new MenuItem('integrations', 'menu.items.integrations', 'plug', 'admin.settings.integrations', permission: 'settings.integrations'),
            ]),
        ];
    }

    /**
     * The menu as the user sees it: invisible items removed, empty groups dropped.
     *
     * @return list<MenuGroup>
     */
    public static function for(User $user): array
    {
        $groups = [];

        foreach (self::definition() as $group) {
            $items = [];

            foreach ($group->items as $item) {
                if ($item->isVisibleTo($user)) {
                    $items[] = $item->forUser($user);
                }
            }

            if ($items !== []) {
                $groups[] = new MenuGroup($group->key, $group->label, $items, $group->collapsible);
            }
        }

        return $groups;
    }

    /**
     * Find a visible item by key.
     *
     * @param  list<MenuGroup>  $groups
     */
    public static function find(array $groups, string $key): ?MenuItem
    {
        foreach ($groups as $group) {
            if ($item = $group->item($key)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Find a visible group by key.
     *
     * @param  list<MenuGroup>  $groups
     */
    public static function group(array $groups, string $key): ?MenuGroup
    {
        foreach ($groups as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        return null;
    }
}
