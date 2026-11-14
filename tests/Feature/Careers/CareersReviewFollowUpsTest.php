<?php

use App\Actions\Careers\SaveVacancy;
use App\Enums\Role;
use App\Enums\VacancyDepartment;
use App\Livewire\Admin\Careers\Vacancies;
use App\Livewire\Site\Careers\Show;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Policies\VacancyPolicy;
use App\Rules\BrandSafeText;
use App\Support\Careers\VacancyDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
| Phase A code-review follow-ups (plan 31) and the careers BrandSafeText context.
*/

it('hides a vacancy whose valid_through is today in UTC but yesterday in Tashkent', function () {
    // 20:00 UTC on 2026-11-20 is already 01:00 on 2026-11-21 in Tashkent (UTC+5).
    $this->travelTo(Carbon::parse('2026-11-20 20:00:00', 'UTC'));
    $vacancy = Vacancy::factory()->published()->create(['valid_through' => '2026-11-20', 'title' => ['uz' => 'Chegara', 'ru' => 'Граница', 'en' => 'Boundary']]);
    VacancyDirectory::flush();

    expect($vacancy->fresh()->isPublic())->toBeFalse()
        ->and(Vacancy::query()->published()->count())->toBe(0)
        ->and(VacancyDirectory::hasPublished())->toBeFalse();

    $this->get('/uz/careers')->assertOk()->assertDontSee('Chegara');
    $this->get($vacancy->url('uz'))->assertNotFound();
});

it('answers 404 on a Livewire re-render after the vacancy was unpublished', function () {
    $vacancy = Vacancy::factory()->published()->create();
    app()->setLocale('uz');
    URL::defaults(['locale' => 'uz']);

    $component = Livewire::test(Show::class, ['slug' => $vacancy->slug_uz])->assertOk();
    $vacancy->forceFill(['is_published' => false])->save();

    $component->call('$refresh')->assertNotFound();
});

it('answers 404, not a 301, for another locale\'s slug of an expired vacancy', function () {
    $vacancy = Vacancy::factory()->expired()->create();

    $this->get("/ru/careers/{$vacancy->slug_uz}")->assertNotFound();
    $this->get("/en/careers/{$vacancy->slug_ru}")->assertNotFound();
});

it('filters the admin vacancy list and counts applications per vacancy', function () {
    $published = Vacancy::factory()->published()->create(['department' => VacancyDepartment::Service, 'title' => ['uz' => 'Servischi', 'ru' => 'С', 'en' => 'S']]);
    Vacancy::factory()->create(['department' => VacancyDepartment::Marketing, 'title' => ['uz' => 'Marketolog', 'ru' => 'М', 'en' => 'M']]);
    JobApplication::factory()->count(2)->forVacancy($published)->create();

    Livewire::actingAs(staffUser())->test(Vacancies\Index::class)
        ->assertSee('Servischi')->assertSee('Marketolog')
        ->assertSeeHtml('data-vacancy="'.$published->id.'"')
        ->set('status', 'draft')->assertDontSee('Servischi')->assertSee('Marketolog')
        ->set('status', 'published')->assertSee('Servischi')->assertDontSee('Marketolog')
        ->call('clearFilters')
        ->set('department', 'marketing')->assertSee('Marketolog')->assertDontSee('Servischi');

    expect(Vacancy::query()->withCount('applications')->find($published->id)->applications_count)->toBe(2);
});

it('round-trips requirement and offer lists through the edit form', function () {
    $vacancy = Vacancy::factory()->create([
        'requirements' => ['uz' => ['Tajriba', 'Masʼuliyat'], 'ru' => ['Опыт'], 'en' => ['Experience']],
        'offer' => ['uz' => ['Rasmiy ish'], 'ru' => [], 'en' => []],
    ]);

    $component = Livewire::actingAs(staffUser())->test(Vacancies\Form::class, ['vacancy' => $vacancy])
        ->assertSet('form.requirements.uz', "Tajriba\nMasʼuliyat")
        ->assertSet('form.offer.uz', 'Rasmiy ish')
        ->call('save')
        ->assertHasNoErrors();

    expect($vacancy->fresh()->requirements['uz'])->toBe(['Tajriba', 'Masʼuliyat'])
        ->and($vacancy->fresh()->offer['uz'])->toBe(['Rasmiy ish']);
});

it('grants every vacancy policy ability only with careers.vacancies', function (string $ability) {
    $vacancy = Vacancy::factory()->create();
    $policy = new VacancyPolicy;
    $call = fn ($user) => in_array($ability, ['viewAny', 'create'], true) ? $policy->{$ability}($user) : $policy->{$ability}($user, $vacancy);

    expect($call(staffUser(Role::Admin)))->toBeTrue();

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        expect($call(staffUser($role)))->toBeFalse();
    }
})->with(['viewAny', 'view', 'create', 'update', 'delete']);

it('keeps the stored slug of an existing vacancy when the slug input is blank', function () {
    $vacancy = Vacancy::factory()->create(['slug_uz' => 'eski-manzil', 'slug_ru' => 'staryi-adres', 'slug_en' => null]);

    $saved = app(SaveVacancy::class)->handle($vacancy, [
        'department' => 'service', 'location' => 'tashkent_city', 'employment_type' => 'full_time',
        'title' => ['uz' => 'Yangi nom', 'ru' => 'Новое название', 'en' => 'New title'],
        'summary' => ['uz' => 'a', 'ru' => 'б', 'en' => 'c'],
        'description' => ['uz' => 'a', 'ru' => 'б', 'en' => 'c'],
        'slug' => ['uz' => '', 'ru' => '  ', 'en' => ''],
    ]);

    expect($saved->slug_uz)->toBe('eski-manzil')
        ->and($saved->slug_ru)->toBe('staryi-adres')
        ->and($saved->slug_en)->toBe('new-title');
});

it('derives the factory department from the vacancy', function () {
    $vacancy = Vacancy::factory()->create(['department' => VacancyDepartment::Operations]);

    expect(JobApplication::factory()->create(['vacancy_id' => $vacancy->id])->department)->toBe(VacancyDepartment::Operations)
        ->and(JobApplication::factory()->forVacancy($vacancy)->create()->department)->toBe(VacancyDepartment::Operations);
});

it('allows "sales" in careers job and department names only', function (string $text, bool $allowed) {
    expect(BrandSafeText::violates($text, BrandSafeText::CAREERS))->toBe(! $allowed);
})->with([
    ['Sales manager', true],
    ['Senior sales managers wanted', true],
    ['Join our sales department', true],
    ['Work in the sales team', true],
    ['B2B sales of Auto-ID equipment', true],
    ['Head of Sales', true],
    ['Sales engineer (barcode printers)', true],
    ['Big sales!', false],
    ['Sales manager! Huge sale', false],
    ['Printers on sale', false],
    ['Sale', false],
    ['Summer sales event', false],
    ['B2B sales!', false],
    ['Sales team — up to −20%', false],
    ['Sales manager, cheapest prices', false],
    ['Скидки для сотрудников', false],
]);

it('keeps "sales" banned everywhere outside the careers context', function () {
    expect(BrandSafeText::violates('Sales manager'))->toBeTrue()
        ->and(BrandSafeText::violates('Sales manager', 'other'))->toBeTrue();
});

it('lets the vacancy form save "Sales manager" but not promo wording', function () {
    $this->actingAs(staffUser());

    Livewire::test(Vacancies\Form::class)
        ->set('form.department', 'sales')->set('form.location', 'tashkent_city')->set('form.employment_type', 'full_time')
        ->set('form.title', ['uz' => 'Savdo menejeri', 'ru' => 'Менеджер по продажам', 'en' => 'Sales manager'])
        ->set('form.summary', ['uz' => 'a', 'ru' => 'б', 'en' => 'B2B sales of scanners'])
        ->set('form.description', ['uz' => 'a', 'ru' => 'б', 'en' => 'Our sales team helps customers.'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Vacancy::query()->sole()->getTranslation('title', 'en'))->toBe('Sales manager');

    Livewire::test(Vacancies\Form::class)
        ->set('form.title.en', 'Big sales!')
        ->call('save')
        ->assertHasErrors(['form.title.en']);
});
