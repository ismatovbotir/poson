<?php

use App\Actions\Careers\DeleteVacancy;
use App\Actions\Careers\PublishVacancy;
use App\Actions\Careers\SaveVacancy;
use App\Enums\VacancyDepartment;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Rules\BrandSafeText;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vacancyData(array $overrides = []): array
{
    return array_replace([
        'department' => 'service',
        'location' => 'tashkent_city',
        'employment_type' => 'full_time',
        'title' => ['uz' => 'Servis muhandisi', 'ru' => 'Сервисный инженер', 'en' => 'Service engineer'],
        'summary' => ['uz' => 'Qisqa', 'ru' => 'Кратко', 'en' => 'Short'],
        'description' => ['uz' => "Bir.\n\nIkki.", 'ru' => "Раз.\n\nДва.", 'en' => "One.\n\nTwo."],
        'requirements' => ['uz' => "Tajriba\n\n  Mas'uliyat ", 'ru' => 'Опыт', 'en' => 'Experience'],
        'offer' => ['uz' => '', 'ru' => '', 'en' => ''],
        'is_published' => false,
    ], $overrides);
}

it('normalises slugs from the titles and turns list text into lists', function () {
    $vacancy = app(SaveVacancy::class)->handle(null, vacancyData(['slug' => ['uz' => 'Servis Muhandisi!!', 'ru' => '', 'en' => '']]));

    expect($vacancy->slug_uz)->toBe('servis-muhandisi')
        ->and($vacancy->slug_ru)->toBe('servisnyi-inzener')
        ->and($vacancy->slug_en)->toBe('service-engineer')
        ->and($vacancy->requirements['uz'])->toBe(['Tajriba', "Mas'uliyat"])
        ->and($vacancy->offer)->toBeNull();
});

it('keeps slugs unique across all three slug columns', function () {
    Vacancy::factory()->create(['slug_uz' => 'taken', 'slug_ru' => 'busy', 'slug_en' => 'engaged']);

    // A generated slug gets a suffix, even when the clash is in another locale's column.
    $generated = app(SaveVacancy::class)->handle(null, vacancyData(['title' => ['uz' => 'Busy', 'ru' => 'Занят', 'en' => 'Taken']]));
    expect($generated->slug_uz)->toBe('busy-2')->and($generated->slug_en)->toBe('taken-2');

    // A typed slug that clashes is an error on that locale.
    expect(fn () => app(SaveVacancy::class)->handle(null, vacancyData(['slug' => ['uz' => 'engaged']])))
        ->toThrow(ValidationException::class);

    // Two locales cannot share one slug either.
    $same = app(SaveVacancy::class)->handle(null, vacancyData(['slug' => ['uz' => 'one', 'ru' => '', 'en' => ''], 'title' => ['uz' => 'One', 'ru' => 'One', 'en' => 'One']]));
    expect($same->slug_uz)->toBe('one')->and($same->slug_ru)->toBe('one-2')->and($same->slug_en)->toBe('one-3');
});

it('lets a vacancy keep its own slugs on edit', function () {
    $vacancy = Vacancy::factory()->create(['slug_uz' => 'mine', 'slug_ru' => 'moy', 'slug_en' => 'my']);

    $saved = app(SaveVacancy::class)->handle($vacancy, vacancyData(['slug' => ['uz' => 'mine', 'ru' => 'moy', 'en' => 'my']]));

    expect($saved->slug_uz)->toBe('mine')->and($saved->slug_en)->toBe('my');
});

it('refuses to publish an incomplete vacancy and sets published_at once', function () {
    expect(fn () => app(SaveVacancy::class)->handle(null, vacancyData(['is_published' => true, 'summary' => ['uz' => 'Qisqa', 'ru' => '', 'en' => '']])))
        ->toThrow(ValidationException::class);
    expect(Vacancy::query()->count())->toBe(0);

    $draft = Vacancy::factory()->onlyUzbek()->create();
    expect(fn () => app(PublishVacancy::class)->handle($draft, true))->toThrow(ValidationException::class);
    expect($draft->fresh()->is_published)->toBeFalse();

    $vacancy = app(SaveVacancy::class)->handle(null, vacancyData(['is_published' => true]));
    $first = $vacancy->published_at;
    expect($first)->not->toBeNull();

    $this->travel(2)->days();
    app(PublishVacancy::class)->handle($vacancy, false);
    app(PublishVacancy::class)->handle($vacancy->fresh(), true);

    expect($vacancy->fresh()->published_at->equalTo($first))->toBeTrue();
});

it('requires the uz texts and rejects banned wording in every locale', function () {
    expect(fn () => app(SaveVacancy::class)->handle(null, vacancyData(['title' => ['uz' => '']])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveVacancy::class)->handle(null, vacancyData(['summary' => ['uz' => 'Qisqa', 'ru' => 'Скидки для всех', 'en' => '']])))->toThrow(ValidationException::class);
    expect(fn () => app(SaveVacancy::class)->handle(null, vacancyData(['requirements' => ['uz' => "Tajriba\nBest discounts"]])))->toThrow(ValidationException::class);
});

it('flags banned wording with the BrandSafeText rule', function () {
    expect(BrandSafeText::violates('Скидки на всё'))->toBeTrue()
        ->and(BrandSafeText::violates('Servis muhandisi'))->toBeFalse();
});

it('strips tags and control characters from vacancy text', function () {
    $vacancy = app(SaveVacancy::class)->handle(null, vacancyData(['description' => ['uz' => "<b>Bir</b>\u{200B}.\n\n\n\nIkki<script>x</script>.", 'ru' => 'Р', 'en' => 'E']]));

    expect($vacancy->getTranslation('description', 'uz'))->toBe("Bir.\n\nIkkix.");
});

it('blocks deleting a vacancy that has applications', function () {
    $vacancy = Vacancy::factory()->create();
    JobApplication::factory()->for($vacancy)->create();

    expect(fn () => app(DeleteVacancy::class)->handle($vacancy))->toThrow(ValidationException::class);
    expect(Vacancy::query()->count())->toBe(1);

    $empty = Vacancy::factory()->create();
    app(DeleteVacancy::class)->handle($empty);
    expect(Vacancy::query()->whereKey($empty->id)->exists())->toBeFalse();
});

it('has an open application factory state without a vacancy', function () {
    $application = JobApplication::factory()->openApplication()->create();

    expect($application->isOpenApplication())->toBeTrue()
        ->and($application->vacancy_id)->toBeNull()
        ->and($application->departmentLabel())->toBe(VacancyDepartment::Sales->label())
        ->and($application->toArray())->not->toHaveKeys(['cv_file_id', 'cv_pending_path']);
});
