<?php

use App\Enums\Role;
use App\Http\Middleware\SetAdminLocale;
use App\Models\User;

it('renders the admin in the user locale', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(['locale' => 'en']))
        ->get('/admin')
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSeeText('Dashboard');
});

it('switches the admin locale and keeps it', function () {
    $user = User::factory()->withRole(Role::ContentManager)->create(['locale' => 'uz']);

    $this->actingAs($user)
        ->from('/admin')
        ->post('/admin/locale', ['locale' => 'ru'])
        ->assertRedirect('/admin');

    expect($user->fresh()->locale)->toBe('ru');

    $this->actingAs($user->fresh())
        ->get('/admin')
        ->assertOk()
        ->assertSee('<html lang="ru"', false)
        ->assertSeeText(['Панель управления', 'Выйти', 'Контент-менеджер']);
});

it('rejects an unsupported locale', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['locale' => 'uz']);

    $this->actingAs($user)
        ->post('/admin/locale', ['locale' => 'de'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('uz');
});

it('never redirects outside the admin after switching', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->from('https://evil.example/phish')
        ->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect(route('admin.dashboard'));
});

it('does not redirect back to a same-host page outside the admin', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->from(url('/uz/catalog'))
        ->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect(route('admin.dashboard'));

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->from(url('/administrator'))
        ->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect(route('admin.dashboard'));
});

it('does not touch any user when a guest switches', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['locale' => 'uz']);
    $updatedAt = $user->updated_at;

    $this->post('/admin/locale', ['locale' => 'ru'])->assertRedirect(route('admin.login'));

    expect(User::where('locale', '!=', 'uz')->count())->toBe(0)
        ->and($user->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('updates users.locale only for staff', function () {
    $user = User::factory()->create(['locale' => 'uz']);

    $this->actingAs($user)
        ->post('/admin/locale', ['locale' => 'ru'])
        ->assertSessionHas(SetAdminLocale::SESSION_KEY, 'ru');

    expect($user->fresh()->locale)->toBe('uz');
});

it('keeps a guest choice in the session on the login page', function () {
    $this->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect(route('admin.login'))
        ->assertSessionHas(SetAdminLocale::SESSION_KEY, 'en');

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSeeText('Sign in to the admin panel');
});
