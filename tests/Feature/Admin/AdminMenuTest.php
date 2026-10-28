<?php

use App\Enums\Role;
use App\Support\Admin\Menu;
use App\Support\Admin\MenuGroup;
use App\Support\Admin\MenuItem;
use Illuminate\Support\Facades\Route;

/**
 * Visible menu as [group key => [item keys]].
 *
 * @return array<string, list<string>>
 */
function menuTree(Role $role): array
{
    return collect(Menu::for(staffUser($role)))
        ->mapWithKeys(fn (MenuGroup $group): array => [
            $group->key => array_map(fn (MenuItem $item): string => $item->key, $group->items),
        ])
        ->all();
}

/**
 * The sidebar <li> of one menu item.
 */
function menuItemHtml(string $html, string $key): string
{
    preg_match('/<li data-menu-item="'.preg_quote($key, '/').'">(.*?)<\/li>/s', $html, $matches);

    expect($matches)->not->toBeEmpty("Menu item [{$key}] is not rendered.");

    return $matches[1];
}

/**
 * The bottom tab bar markup.
 */
function tabBarHtml(string $html): string
{
    preg_match('/<nav class="fixed inset-x-0 bottom-0.*?<\/nav>/s', $html, $matches);

    return $matches[0] ?? '';
}

$catalogItems = ['categories', 'attributes', 'brands', 'products', 'roll-types'];

it('shows each role its part of the menu', function (Role $role, array $expected) {
    expect(menuTree($role))->toBe($expected);
})->with([
    'admin' => [Role::Admin, [
        'dashboard' => ['dashboard', 'analytics', 'ai-visibility'],
        'sales' => ['leads', 'customers', 'quotes', 'orders', 'payments'],
        'catalog' => [...$catalogItems, 'price-sync'],
        'service' => ['service-requests', 'repairs'],
        'content' => ['pages', 'news', 'articles', 'banners'],
        'telegram' => ['telegram-bot', 'telegram-mini-app', 'telegram-chats'],
        'settings' => ['company', 'users', 'integrations', 'api-tokens'],
    ]],
    'sales manager' => [Role::SalesManager, [
        'dashboard' => ['dashboard'],
        'sales' => ['leads', 'customers', 'quotes', 'orders', 'payments'],
        'catalog' => $catalogItems,
        'service' => ['service-requests'],
        'telegram' => ['telegram-chats'],
    ]],
    'service engineer' => [Role::ServiceEngineer, [
        'dashboard' => ['dashboard'],
        'sales' => ['leads', 'customers', 'orders'],
        'catalog' => $catalogItems,
        'service' => ['service-requests', 'repairs'],
    ]],
    'content manager' => [Role::ContentManager, [
        'dashboard' => ['dashboard'],
        'catalog' => $catalogItems,
        'content' => ['pages', 'news', 'articles', 'banners'],
    ]],
]);

it('renders only the visible groups in the sidebar', function () {
    $html = $this->actingAs(staffUser(Role::ServiceEngineer))->get('/admin')->assertOk()->getContent();

    expect($html)
        ->toContain('data-menu-group="service"')
        ->toContain('data-menu-group="catalog"')
        ->toContain('data-menu-group="sales"')
        ->not->toContain('data-menu-group="settings"')
        ->not->toContain('data-menu-group="content"')
        ->not->toContain('data-menu-item="payments"');
});

it('renders a soon item as a non-link with the badge', function () {
    $soon = collect(Menu::definition())
        ->flatMap(fn (MenuGroup $group): array => $group->items)
        ->first(fn (MenuItem $item): bool => $item->isSoon());

    if ($soon === null) {
        $this->markTestSkipped('Every menu module is built.');
    }

    $html = $this->actingAs(staffUser(Role::Admin))->get('/admin')->assertOk()->getContent();
    $item = menuItemHtml($html, $soon->key);

    expect($item)
        ->toContain('aria-disabled="true"')
        ->toContain('Tez orada')
        ->toContain('title="'.__('menu.soon_hint').'"')
        ->toContain(__($soon->label))
        ->not->toContain('<a ')
        ->not->toMatch('/\shref="(?!#i-)/')
        ->not->toContain('tabindex');
});

it('links an available item to its route', function () {
    $html = $this->actingAs(staffUser(Role::SalesManager))->get('/admin')->assertOk()->getContent();

    expect(menuItemHtml($html, 'categories'))
        ->toContain('href="'.route('admin.categories.index').'"')
        ->toContain('wire:navigate')
        ->not->toContain('aria-disabled')
        ->not->toContain('Tez orada');

    expect(menuItemHtml($html, 'dashboard'))
        ->toContain('href="'.route('admin.dashboard').'"')
        ->toContain('aria-current="page"');
});

it('turns an item into a link once its route is registered', function () {
    Route::get('/admin/leads', fn () => 'leads')->name('admin.leads.index');
    app('router')->getRoutes()->refreshNameLookups();

    $html = $this->actingAs(staffUser(Role::SalesManager))->get('/admin')->assertOk()->getContent();

    expect(menuItemHtml($html, 'leads'))
        ->toContain('href="'.url('/admin/leads').'"')
        ->not->toContain('aria-disabled')
        ->not->toContain('Tez orada');

    expect(tabBarHtml($html))->toMatch('/<a href="'.preg_quote(url('/admin/leads'), '/').'"[^>]*data-tab="leads"/');
});

it('shows the bottom tab bar per role and keeps the menu tab', function () {
    $sales = tabBarHtml($this->actingAs(staffUser(Role::SalesManager))->get('/admin')->assertOk()->getContent());

    expect($sales)
        ->toContain('data-tab="home"')
        ->toContain('data-tab="leads"')
        ->toContain('data-tab="catalog"')
        ->toContain('data-tab="orders"')
        ->toContain('data-tab="menu"')
        ->toContain('href="'.route('admin.categories.index').'" wire:navigate data-tab="catalog"');

    if (! Route::has('admin.orders.index')) {
        expect($sales)->toMatch('/<span aria-disabled="true"[^>]*data-tab="orders" data-soon/');
    }

    $service = tabBarHtml($this->actingAs(staffUser(Role::ServiceEngineer))->get('/admin')->assertOk()->getContent());

    expect($service)
        ->toContain('data-tab="home"')
        ->toContain('data-tab="leads"')
        ->toContain('data-tab="catalog"')
        ->toContain('data-tab="orders"')
        ->toContain('data-tab="menu"');

    $content = tabBarHtml($this->actingAs(staffUser(Role::ContentManager))->get('/admin')->assertOk()->getContent());

    expect($content)
        ->toContain('data-tab="home"')
        ->toContain('data-tab="catalog"')
        ->toContain('data-tab="menu"')
        ->not->toContain('data-tab="leads"')
        ->not->toContain('data-tab="orders"');
});

it('translates every menu label in all locales', function (string $locale) {
    app()->setLocale($locale);

    foreach (Menu::definition() as $group) {
        expect(__($group->label))->not->toBe($group->label);

        foreach ($group->items as $item) {
            expect(__($item->label))->not->toBe($item->label);
        }
    }
})->with(['uz', 'ru', 'en']);

/**
 * aria-expanded of each collapsible group header as [group key => "true"|"false"].
 *
 * @return array<string, string>
 */
function menuGroupStates(string $html): array
{
    preg_match_all('/<button\s[^>]*?aria-expanded="(true|false)"[^>]*?aria-controls="menu-group-([\w-]+)"/s', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $match): array => [$match[2] => $match[1]])->all();
}

/**
 * The opening <ul> tag of a sidebar group.
 */
function menuGroupListTag(string $html, string $group): string
{
    preg_match('/<ul\s[^>]*id="menu-group-'.preg_quote($group, '/').'"[^>]*>/s', $html, $matches);

    return $matches[0] ?? '';
}

it('expands only the group of the current page', function (string $route, ?string $openGroup) {
    $html = $this->actingAs(staffUser(Role::Admin))->get(route($route))->assertOk()->getContent();

    $states = menuGroupStates($html);

    expect($states)
        ->toHaveKeys(['sales', 'catalog', 'content', 'settings'])
        ->not->toHaveKey('dashboard');

    foreach ($states as $group => $expanded) {
        $isOpen = $group === $openGroup;

        expect($expanded)->toBe($isOpen ? 'true' : 'false', "Group [{$group}] aria-expanded");

        $isOpen
            ? expect(menuGroupListTag($html, $group))->not->toContain('display: none')
            : expect(menuGroupListTag($html, $group))->toContain('style="display: none;"');
    }

    // Single items (no collapsible group) are still rendered and never hidden.
    expect($html)->toContain('data-menu-item="dashboard"');
    expect(menuGroupListTag($html, 'dashboard'))
        ->not->toBe('')
        ->not->toContain('display: none')
        ->not->toContain('x-show');
})->with([
    'dashboard: all collapsed' => ['admin.dashboard', null],
    'categories: catalog open' => ['admin.categories.index', 'catalog'],
    'leads: sales open' => ['admin.leads.index', 'sales'],
    'pages: content open' => ['admin.pages.index', 'content'],
]);
