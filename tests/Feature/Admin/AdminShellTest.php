<?php

use App\Enums\Role;

it('escapes menu labels and never uses x-html or raw output in the sidebar (f)', function () {
    foreach (['layouts/admin.blade.php', 'components/admin/menu-item.blade.php'] as $file) {
        $source = file_get_contents(resource_path('views/'.$file));

        expect($source)->not->toContain('x-html')
            ->and($source)->not->toContain('{!!');
    }
});

it('hides the Users and Settings items from a content manager (f)', function () {
    $this->actingAs(staffUser(Role::ContentManager));

    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-rail-toggle', false)
        ->assertDontSee('data-menu-item="users"', false)
        ->assertDontSee('data-menu-item="company"', false);
});

it('shows the Users item to an admin (f)', function () {
    $this->actingAs(staffUser(Role::Admin));

    $this->get(route('admin.dashboard'))->assertOk()->assertSee('data-menu-item="users"', false);
});

it('scales only the admin html, not the public site (g)', function () {
    $this->actingAs(staffUser(Role::Admin));

    expect($this->get(route('admin.dashboard'))->assertOk()->getContent())->toMatch('/<html[^>]*\sdata-admin[\s>]/');

    $this->get('/uz')->assertOk()->assertDontSee('data-admin', false);
});

it('scales the admin login screen too (g)', function () {
    $this->get(route('admin.login'))->assertOk()->assertSee('data-admin', false);
});

it('renders the rail toggle, the head script and the sr-only labels (8)', function () {
    $this->actingAs(staffUser(Role::Admin));

    $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->toContain('poson.admin.sidebar')
        ->and($html)->toContain("=== '1'")
        ->and($html)->toContain('data-rail-toggle')
        ->and($html)->toContain('aria-pressed')
        ->and($html)->toContain('data-admin-side')
        ->and($html)->toContain('lg:w-(--admin-side)')
        ->and($html)->toContain('lg:pl-(--admin-side)')
        ->and($html)->toMatch('/rail:lg:sr-only[^>]*>'.preg_quote(e(__('menu.items.dashboard')), '/').'</')
        ->and($html)->not->toContain('lg:left-[18rem]');
});

it('offsets the sticky save bar by the rail variable', function () {
    expect(file_get_contents(resource_path('views/components/admin/sticky-save-bar.blade.php')))
        ->toContain('lg:left-(--admin-side)')
        ->not->toContain('18rem');
});
