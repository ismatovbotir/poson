<?php

use App\Actions\Careers\InstallSampleVacancies;
use App\Models\Vacancy;

/**
 * Plan 31 phase C: php artisan careers:install-samples (CR-15).
 */
function sampleFile(callable $change): string
{
    $samples = require database_path('seeders/data/vacancies_sample.php');
    $samples = $change($samples);
    $path = tempnam(sys_get_temp_dir(), 'vac').'.php';
    file_put_contents($path, '<?php return '.var_export($samples, true).';');

    return $path;
}

it('installs the four sample vacancies published, and never overwrites on a second run', function () {
    $this->artisan('careers:install-samples')->assertSuccessful()->expectsOutputToContain('4 vacancy(ies) created');

    expect(Vacancy::query()->count())->toBe(4)->and(Vacancy::query()->where('is_published', true)->count())->toBe(4);

    Vacancy::query()->where('slug_uz', 'servis-muhandisi')->update(['sort' => 99]);

    $this->artisan('careers:install-samples')->assertSuccessful()->expectsOutputToContain('0 vacancy(ies) created, 4 already existed');

    expect(Vacancy::query()->count())->toBe(4)->and(Vacancy::query()->where('slug_uz', 'servis-muhandisi')->value('sort'))->toBe(99);
});

it('installs unpublished with --draft', function () {
    $this->artisan('careers:install-samples --draft')->assertSuccessful();

    expect(Vacancy::query()->count())->toBe(4)->and(Vacancy::query()->where('is_published', true)->count())->toBe(0);
});

it('installs nothing in production without --force when not interactive', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('careers:install-samples', ['--no-interaction' => true])->assertFailed();

    expect(Vacancy::query()->count())->toBe(0);
});

it('installs drafts in production with --force', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('careers:install-samples --force')->assertSuccessful();

    expect(Vacancy::query()->count())->toBe(4)->and(Vacancy::query()->where('is_published', true)->count())->toBe(0);
});

it('fails fast on a wrong sample shape without writing anything', function () {
    $path = sampleFile(function (array $samples): array {
        $samples[1]['location'] = 'Tashkent';

        return $samples;
    });

    expect(fn () => app(InstallSampleVacancies::class)->handle(false, $path))
        ->toThrow(InvalidArgumentException::class, 'invalid "location"');
    expect(Vacancy::query()->count())->toBe(0);
});

it('applies the careers brand check: B2B sales manager passes, a discount word is rejected', function () {
    // The shipped samples include "B2B sales manager".
    $this->artisan('careers:install-samples --draft')->assertSuccessful();
    expect(Vacancy::query()->where('slug_uz', 'like', '%sotuv%')->orWhere('slug_en', 'b2b-sales-manager')->exists())->toBeTrue();

    $path = sampleFile(function (array $samples): array {
        $samples = [$samples[0]];
        $samples[0]['slug'] = ['uz' => 'yangi-uz', 'ru' => 'novyj-ru', 'en' => 'new-en'];
        $samples[0]['summary']['uz'] = 'Katta chegirma bilan ishlash';

        return $samples;
    });

    $result = app(InstallSampleVacancies::class)->handle(false, $path);

    expect($result)->toBe(['created' => 0, 'skipped' => 0, 'failed' => 1])
        ->and(Vacancy::query()->where('slug_uz', 'yangi-uz')->exists())->toBeFalse();
});
