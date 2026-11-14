<?php

namespace App\Actions\Careers;

use App\Enums\EmploymentType;
use App\Enums\Region;
use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Install the sample vacancies of database/seeders/data/vacancies_sample.php
 * (plan 31 §12, CR-15) through SaveVacancy, so validation, plain-text cleaning
 * and the brand check (careers context) apply exactly as in admin. Never
 * overwrites: a sample whose slug_uz exists is skipped. The data shape is
 * checked before anything is written (fail fast with a clear message).
 */
class InstallSampleVacancies
{
    public const FILE = 'seeders/data/vacancies_sample.php';

    public function __construct(protected SaveVacancy $saveVacancy) {}

    /**
     * @return array{created: int, skipped: int, failed: int}
     *
     * @throws InvalidArgumentException when the data file has a wrong shape
     */
    public function handle(bool $publish, ?string $path = null): array
    {
        $samples = $this->load($path ?? database_path(self::FILE));
        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($samples as $sample) {
            if (Vacancy::query()->where('slug_uz', $sample['slug']['uz'])->exists()) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->saveVacancy->handle(null, [
                    'department' => $sample['department'],
                    'location' => $sample['location'],
                    'employment_type' => $sample['employment_type'],
                    'title' => $sample['title'],
                    'slug' => $sample['slug'],
                    'summary' => $sample['summary'],
                    'description' => $sample['description'],
                    'requirements' => $sample['requirements'],
                    'offer' => $sample['offer'],
                    'meta_title' => $sample['meta_title'],
                    'meta_description' => $sample['meta_description'],
                    'is_published' => $publish && $sample['is_published'],
                    'sort' => $sample['sort'],
                ]);
                $result['created']++;
            } catch (ValidationException) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * The validated samples.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Sample file not found: {$path}");
        }

        $samples = require $path;

        if (! is_array($samples) || $samples === [] || ! array_is_list($samples)) {
            throw new InvalidArgumentException('Sample file must return a non-empty list of vacancies.');
        }

        $locales = config('app.supported_locales');
        $seen = [];

        foreach ($samples as $index => $sample) {
            $at = "Sample #{$index}";

            if (! is_array($sample)) {
                throw new InvalidArgumentException("{$at} must be an array.");
            }

            $this->enum($at, 'department', $sample, VacancyDepartment::class);
            $this->enum($at, 'location', $sample, Region::class);
            $this->enum($at, 'employment_type', $sample, EmploymentType::class);

            foreach (['is_published' => 'is_bool', 'sort' => 'is_int'] as $field => $check) {
                if (! isset($sample[$field]) || ! $check($sample[$field])) {
                    throw new InvalidArgumentException("{$at}: invalid or missing \"{$field}\".");
                }
            }

            if (! is_string($sample['key'] ?? null) || $sample['key'] === '') {
                throw new InvalidArgumentException("{$at}: missing \"key\".");
            }

            foreach (['title', 'slug', 'summary', 'description', 'meta_title', 'meta_description'] as $field) {
                foreach ($locales as $locale) {
                    if (! is_string($sample[$field][$locale] ?? null) || trim($sample[$field][$locale]) === '') {
                        throw new InvalidArgumentException("{$at}: \"{$field}.{$locale}\" must be a non-empty string.");
                    }
                }
            }

            foreach (['requirements', 'offer'] as $field) {
                foreach ($locales as $locale) {
                    $items = $sample[$field][$locale] ?? null;

                    if (! is_array($items) || ! array_is_list($items) || $items === [] || array_filter($items, fn ($item): bool => ! is_string($item) || trim($item) === '') !== []) {
                        throw new InvalidArgumentException("{$at}: \"{$field}.{$locale}\" must be a non-empty list of strings.");
                    }
                }
            }

            foreach ($locales as $locale) {
                $slug = $sample['slug'][$locale];

                if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > Vacancy::SLUG_MAX) {
                    throw new InvalidArgumentException("{$at}: \"slug.{$locale}\" is not a valid slug.");
                }

                if (isset($seen[$slug])) {
                    throw new InvalidArgumentException("{$at}: slug \"{$slug}\" is used twice in the file.");
                }

                $seen[$slug] = true;

                if ($sample['description'][$locale] !== strip_tags($sample['description'][$locale])) {
                    throw new InvalidArgumentException("{$at}: \"description.{$locale}\" must be plain text.");
                }
            }
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $sample
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enum(string $at, string $field, array $sample, string $enum): void
    {
        if (! is_string($sample[$field] ?? null) || $enum::tryFrom($sample[$field]) === null) {
            throw new InvalidArgumentException("{$at}: invalid \"{$field}\".");
        }
    }
}
