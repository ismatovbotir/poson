<?php

use App\Enums\Role;
use App\Livewire\Admin\Careers\Applications;
use App\Livewire\Admin\Careers\Vacancies;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Support\Admin\Menu;
use Livewire\Livewire;

dataset('careersScreens', [
    'vacancies' => fn () => route('admin.careers.vacancies.index'),
    'vacancy create' => fn () => route('admin.careers.vacancies.create'),
    'vacancy edit' => fn () => route('admin.careers.vacancies.edit', Vacancy::factory()->create()),
    'applications' => fn () => route('admin.careers.applications.index'),
    'application show' => fn () => route('admin.careers.applications.show', JobApplication::factory()->create()->id),
]);

it('shows the careers screens to Admin only', function (Closure $url) {
    $url = $url();

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        $this->actingAs(staffUser($role))->get($url)->assertForbidden();
    }

    $this->actingAs(staffUser(Role::Admin))->get($url)->assertOk();
})->with('careersScreens');

it('hides the careers menu and badge from other roles', function () {
    JobApplication::factory()->openApplication()->create();

    $keys = fn ($user) => collect(Menu::for($user))->pluck('key')->all();

    expect($keys(staffUser(Role::SalesManager)))->not->toContain('careers')
        ->and($keys(staffUser(Role::ContentManager)))->not->toContain('careers')
        ->and(Menu::badges(staffUser(Role::SalesManager)))->not->toHaveKey('careers-applications');

    $admin = staffUser(Role::Admin);
    expect($keys($admin))->toContain('careers')->and(Menu::badges($admin)['careers-applications'])->toBe(1);
});

it('creates a vacancy through the form with list text and publishes it', function () {
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Vacancies\Form::class)
        ->set('form.department', 'sales')
        ->set('form.location', 'samarkand')
        ->set('form.employment_type', 'part_time')
        ->set('form.title', ['uz' => 'Menejer', 'ru' => 'Менеджер', 'en' => 'Manager'])
        ->set('form.summary', ['uz' => 'a', 'ru' => 'б', 'en' => 'c'])
        ->set('form.description', ['uz' => 'a', 'ru' => 'б', 'en' => 'c'])
        ->set('form.requirements.uz', "Bir\nIkki")
        ->set('form.is_published', true)
        ->call('save')
        ->assertHasNoErrors();

    $vacancy = Vacancy::query()->firstOrFail();
    expect($vacancy->is_published)->toBeTrue()
        ->and($vacancy->requirements['uz'])->toBe(['Bir', 'Ikki'])
        ->and($vacancy->slug_en)->toBe('manager');
});

it('shows an incomplete publish and banned wording as form errors', function () {
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Vacancies\Form::class)
        ->set('form.department', 'sales')
        ->set('form.location', 'samarkand')
        ->set('form.employment_type', 'part_time')
        ->set('form.title.uz', 'Sotuv')
        ->set('form.summary.uz', 'a')
        ->set('form.description.uz', 'a')
        ->set('form.is_published', true)
        ->call('save')
        ->assertHasErrors(['form.is_published']);

    Livewire::test(Vacancies\Form::class)
        ->set('form.title.uz', 'Eng arzon narx')
        ->call('save')
        ->assertHasErrors(['form.title.uz']);
});

it('toggles publication and blocks deleting from the list', function () {
    $this->actingAs(staffUser(Role::Admin));
    $vacancy = Vacancy::factory()->create();
    JobApplication::factory()->for($vacancy)->create();

    Livewire::test(Vacancies\Index::class)->call('togglePublished', $vacancy->id)->call('delete', $vacancy->id);

    expect($vacancy->fresh()->is_published)->toBeTrue()->and(Vacancy::query()->count())->toBe(1);
});

it('refuses Livewire calls from a user without the permission', function () {
    $this->actingAs(staffUser(Role::SalesManager));

    Livewire::test(Vacancies\Index::class)->assertForbidden();
    Livewire::test(Applications\Index::class)->assertForbidden();
});
