<?php

namespace App\Support\Admin;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Lead;
use App\Models\Setting;
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
        $admin = [Role::Admin];

        return [
            new MenuGroup('dashboard', 'menu.groups.dashboard', [
                new MenuItem('dashboard', 'menu.items.dashboard', 'dashboard', 'admin.dashboard'),
                // Plan 04c (labels in lang/*/analytics.php). Explicit active patterns: the two routes share a prefix.
                new MenuItem('analytics', 'analytics.menu.traffic', 'chart', 'admin.analytics.index', permission: 'analytics.view', active: 'admin.analytics.index'),
                new MenuItem('ai-visibility', 'analytics.menu.ai', 'radar', 'admin.analytics.ai', permission: 'analytics.view', active: 'admin.analytics.ai'),
            ], collapsible: false),

            new MenuGroup('sales', 'menu.groups.sales', [
                // Plan 05 5a. Badge: new leads the user can see (badges()).
                new MenuItem('leads', 'menu.items.leads', 'inbox', 'admin.leads.index', permission: 'leads.viewAny'),
                new MenuItem('customers', 'menu.items.customers', 'building', 'admin.customers.index', permission: 'customers.viewAny'),
                new MenuItem('quotes', 'menu.items.quotes', 'file-text', 'admin.quotes.index', permission: 'quotes.viewAny'),
                // Plan 11b: orders (Admin, Sales, Service engineer read-only) and the payment ledger.
                new MenuItem('orders', 'menu.items.orders', 'bag', 'admin.orders.index', permission: 'orders.viewAny'),
                new MenuItem('payments', 'menu.items.payments', 'card', 'admin.payments.index', permission: 'payments.viewAny'),
            ]),

            new MenuGroup('catalog', 'menu.groups.catalog', [
                new MenuItem('categories', 'menu.items.categories', 'folder', 'admin.categories.index', permission: 'categories.viewAny'),
                new MenuItem('attributes', 'menu.items.attributes', 'sliders', 'admin.attributes.index', permission: 'attributes.viewAny'),
                new MenuItem('brands', 'menu.items.brands', 'tag', 'admin.brands.index', permission: 'brands.viewAny'),
                new MenuItem('products', 'menu.items.products', 'box', 'admin.products.index', permission: 'products.viewAny'),
                // Plan 08: label roll types (labels per roll + core).
                new MenuItem('roll-types', 'menu.items.roll_types', 'list', 'admin.roll-types.index', permission: 'roll-types.viewAny'),
                // Plan 09: the price API (1C) batch log.
                new MenuItem('price-sync', 'menu.items.price_sync', 'upload', 'admin.price-sync.index', permission: 'price-sync.view'),
            ]),

            new MenuGroup('service', 'menu.groups.service', [
                // Redirects to the leads list filtered to service requests (LeadPolicy::viewServiceRequests).
                new MenuItem('service-requests', 'menu.items.service_requests', 'wrench', 'admin.service-requests.index', permission: ['leads.service', 'leads.service-view'], active: 'admin.service-requests.index'),
                new MenuItem('repairs', 'menu.items.repairs', 'tool', 'admin.repairs.index', roles: $service),
            ]),

            new MenuGroup('content', 'menu.groups.content', [
                // Plan 07: pages.* / news.* / banners.* (Content manager + Admin).
                new MenuItem('pages', 'menu.items.pages', 'page', 'admin.pages.index', permission: 'pages.viewAny'),
                new MenuItem('news', 'menu.items.news', 'news', 'admin.news.index', permission: 'news.viewAny'),
                // Knowledge articles (decision 2026-10-24): news_posts with kind = article, same news.* permissions.
                new MenuItem('articles', 'menu.items.articles', 'book', 'admin.articles.index', permission: 'news.viewAny'),
                new MenuItem('banners', 'menu.items.banners', 'image', 'admin.banners.index', permission: 'banners.viewAny'),
            ]),

            new MenuGroup('telegram', 'menu.groups.telegram', [
                new MenuItem('telegram-bot', 'menu.items.telegram_bot', 'bot', 'admin.telegram.bot', roles: $admin),
                new MenuItem('telegram-mini-app', 'menu.items.telegram_mini_app', 'smartphone', 'admin.telegram.mini-app', roles: $admin),
                new MenuItem('telegram-chats', 'menu.items.telegram_chats', 'message', 'admin.telegram.chats', roles: $sales),
            ]),

            new MenuGroup('settings', 'menu.groups.settings', [
                new MenuItem('company', 'menu.items.company', 'briefcase', 'admin.settings.company', permission: 'settings.company'),
                new MenuItem('users', 'menu.items.users', 'users', 'admin.users.index', roles: $admin),
                // Plan 11b: delivery fees, order caps, IKPU, UzQR (Admin).
                new MenuItem('checkout', 'menu.items.checkout', 'bag', 'admin.settings.checkout', permission: 'settings.checkout'),
                // Plan 15 C: search indexing switch (Admin: settings.company + settings.integrations).
                new MenuItem('indexing', 'menu.items.indexing', 'globe', 'admin.settings.indexing', permission: 'settings.integrations', visible: fn (User $user): bool => $user->can('manageArea', [Setting::class, SettingsArea::Indexing])),
                new MenuItem('integrations', 'menu.items.integrations', 'plug', 'admin.settings.integrations', permission: 'settings.integrations'),
                new MenuItem('api-tokens', 'menu.items.api_tokens', 'shield', 'admin.settings.api-tokens', permission: 'api-tokens.manage'),
                // Plan 13: the CEO's Claude connection (single holder, tokens, call log). Admin only.
                new MenuItem('mcp', 'menu.items.mcp', 'bot', 'admin.settings.mcp', permission: 'mcp.manage'),
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
     * Counters next to menu items (item key => count; zero counts left out):
     * "leads" = new leads the user can see.
     *
     * @return array<string, int>
     */
    public static function badges(User $user): array
    {
        $badges = [];

        if ($user->can('viewAny', Lead::class)) {
            $badges['leads'] = Lead::query()->visibleTo($user)->where('status', LeadStatus::New->value)->count();
        }

        return array_filter($badges);
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
