<?php

use App\Enums\Role;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Dashboard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('signs out non-staff users and sends them to the login page', function (Closure $makeUser) {
    $this->actingAs($makeUser())->withSession(['marker' => 'old session']);

    $this->get('/admin')
        ->assertRedirect(route('admin.login'))
        ->assertSessionMissing('marker')
        ->assertSessionHas(EnsureStaff::NOTICE_KEY, 'Seans yakunlandi. Iltimos, qaytadan kiring.');

    $this->assertGuest();

    $this->get('/admin/login')
        ->assertOk()
        ->assertSeeText('Seans yakunlandi. Iltimos, qaytadan kiring.');
})->with([
    'user without a staff role' => [fn () => User::factory()->create()],
    'inactive staff' => [fn () => User::factory()->withRole(Role::Admin)->inactive()->create()],
]);

it('returns 403 to JSON requests from non-staff users', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->inactive()->create())
        ->getJson('/admin')
        ->assertForbidden();

    $this->assertGuest();
});

it('returns 403 to a Livewire update after the user is deactivated mid-session', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    $html = $this->actingAs($user)->get('/admin')->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1] ?? '', ENT_QUOTES);
    expect($snapshot)->toContain('admin.dashboard');

    $user->forceFill(['is_active' => false])->save();

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
                'updates' => [],
            ]],
        ])
        ->assertForbidden();

    $this->assertGuest();
});

it('lets active staff make Livewire updates', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    $html = $this->actingAs($user)->get('/admin')->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1] ?? '', ENT_QUOTES),
                'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
                'updates' => [],
            ]],
        ])
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('opens the dashboard for every staff role', function (Role $role) {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(User::factory()->withRole($role)->create(['name' => 'Aziz Karimov']))
        ->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertSeeText('Aziz Karimov')
        ->assertSeeText($role->label())
        ->assertSee('data-role="'.$role->value.'"', false);
})->with(Role::cases());

it('shows the catalog menu only to roles that may view it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(User::factory()->withRole(Role::SalesManager)->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeTextInOrder(['Toifalar', 'Xususiyatlar', 'Brendlar', 'Mahsulotlar']);

    $withoutPermissions = User::factory()->create();
    $withoutPermissions->assignRole(Spatie\Permission\Models\Role::findOrCreate('content_manager', 'web')->syncPermissions([]));

    $this->actingAs($withoutPermissions)
        ->get('/admin')
        ->assertOk()
        ->assertDontSeeText('Mahsulotlar');
});

it('renders the admin layout without the public site bundle', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('id="admin-sidebar"', false)
        ->assertSee('class="invisible fixed inset-y-0', false)
        ->assertSee('name="robots" content="noindex, nofollow"', false)
        ->assertSee('/build/assets/admin-', false)
        ->assertDontSee('/build/assets/app-', false);
})->skip(fn () => file_exists(public_path('hot')), 'Vite dev server running');
