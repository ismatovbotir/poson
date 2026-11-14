<?php

use App\Actions\Careers\SaveCareersSettings;
use App\Enums\Role;
use App\Livewire\Admin\Careers\Settings;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\Vacancy;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/**
 * Plan 31 phase C: the Admin-only kill switches (CR-3 / CR-11).
 */
it('opens the switches screen for Admin only', function () {
    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        $this->actingAs(staffUser($role))->get(route('admin.careers.settings'))->assertForbidden();
        Livewire::actingAs(staffUser($role))->test(Settings::class)->assertForbidden();
    }

    $this->actingAs(staffUser(Role::Admin))->get(route('admin.careers.settings'))
        ->assertOk()->assertSee('data-careers-settings', false)->assertSee('data-cap="applications_per_hour"', false);
});

it('saves the switches, audits each change without values and hides the form', function () {
    $admin = staffUser(Role::Admin);
    $vacancy = Vacancy::factory()->published()->create();
    $this->get($vacancy->url('uz'))->assertSee('data-careers-form', false);

    Livewire::actingAs($admin)->test(Settings::class)
        ->assertSet('applications_enabled', true)
        ->set('applications_enabled', false)
        ->set('cv_enabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('applications_enabled', false);

    expect(SettingsAudit::query()->where('area', 'careers')->orderBy('key')->pluck('key')->all())
        ->toBe(['careers.applications_enabled', 'careers.cv_enabled']);

    $this->get($vacancy->url('uz'))->assertOk()->assertDontSee('data-careers-form', false);
    postVacancyApplication($vacancy)->assertSessionHasErrors(['form'], errorBag: 'careers');
    expect(JobApplication::query()->count())->toBe(0);
});

it('writes no audit row when nothing changed', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(Settings::class)->call('save');

    expect(SettingsAudit::query()->count())->toBe(0);
});

it('refuses a non-admin save at the action whatever the caller', function () {
    expect(fn () => app(SaveCareersSettings::class)->handle(['cv_enabled' => false], staffUser(Role::ContentManager), '127.0.0.1'))
        ->toThrow(AuthorizationException::class);

    expect(SettingsAudit::query()->count())->toBe(0);
});

it('shows the management chat as not configured without a bot', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(Settings::class)->assertSeeHtml('data-chat-configured="no"');
});

it('answers 403 and writes nothing when the admin role was revoked after mount', function () {
    $admin = staffUser(Role::Admin);
    $component = Livewire::actingAs($admin)->test(Settings::class);

    $admin->syncRoles([Role::ContentManager->value]);
    $admin->forgetCachedPermissions();

    $component->call('save')->assertForbidden();

    expect(SettingsAudit::query()->count())->toBe(0)
        ->and(Setting::query()->where('key', 'careers.cv_enabled')->exists())->toBeFalse();
});
