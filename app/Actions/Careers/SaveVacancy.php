<?php

namespace App\Actions\Careers;

use App\Enums\EmploymentType;
use App\Enums\Region;
use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use App\Rules\BrandSafeText;
use App\Support\PlainText;
use App\Support\Slug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a vacancy (plan 31). The form validates first; this action
 * re-checks what must never be stored whatever the caller:
 *  - texts are plain text (tags and control characters stripped), the uz
 *    title, summary and description are present, lengths, BrandSafeText in
 *    every locale (public copy);
 *  - slugs are normalised (Slug::make, from the title when blank and the
 *    record has no stored slug for that locale: a blank input keeps a live
 *    URL) and unique
 *    across ALL THREE slug columns, the record's own slugs excepted (CR-12):
 *    a typed slug that is taken is an error, a generated one gets "-2";
 *  - a published vacancy must be complete in every locale (isComplete());
 *    published_at is set once, on the first publication.
 *
 * @phpstan-type Data array{department: string|VacancyDepartment, location: string|Region, employment_type: string|EmploymentType, title: array<string, string|null>, summary: array<string, string|null>, description: array<string, string|null>, requirements?: array<string, mixed>, offer?: array<string, mixed>, meta_title?: array<string, string|null>, meta_description?: array<string, string|null>, slug?: array<string, string|null>, is_published?: bool, valid_through?: string|null, sort?: int|string|null}
 */
class SaveVacancy
{
    /**
     * @param  Data  $data
     *
     * @throws ValidationException
     */
    public function handle(?Vacancy $vacancy, array $data): Vacancy
    {
        $locales = config('app.supported_locales');
        $title = $this->texts($data['title'] ?? [], false);
        $summary = $this->texts($data['summary'] ?? [], true);
        $description = $this->texts($data['description'] ?? [], true);
        $metaTitle = $this->texts($data['meta_title'] ?? [], false);
        $metaDescription = $this->texts($data['meta_description'] ?? [], false);
        $requirements = $this->lists($data['requirements'] ?? []);
        $offer = $this->lists($data['offer'] ?? []);

        $this->check($title, $summary, $description, $metaTitle, $metaDescription, $requirements, $offer);

        $department = VacancyDepartment::tryFrom($this->value($data['department'] ?? null));
        $location = Region::tryFrom($this->value($data['location'] ?? null));
        $type = EmploymentType::tryFrom($this->value($data['employment_type'] ?? null));

        foreach (['department' => $department, 'location' => $location, 'employment_type' => $type] as $field => $enum) {
            if ($enum === null) {
                throw ValidationException::withMessages([$field => __('validation.in', ['attribute' => __("careers.admin.fields.{$field}")])]);
            }
        }

        $slugs = $this->slugs($vacancy, $data['slug'] ?? [], $title);
        $publish = (bool) ($data['is_published'] ?? false);
        $validThrough = filled($data['valid_through'] ?? null) ? (string) $data['valid_through'] : null;
        $sort = max(0, min(65535, (int) ($data['sort'] ?? 0)));

        try {
            return DB::transaction(function () use ($vacancy, $locales, $title, $summary, $description, $metaTitle, $metaDescription, $requirements, $offer, $department, $location, $type, $slugs, $publish, $validThrough, $sort): Vacancy {
                $vacancy ??= new Vacancy;

                $vacancy->fill([
                    'department' => $department,
                    'location' => $location,
                    'employment_type' => $type,
                    'requirements' => $requirements,
                    'offer' => $offer,
                    'valid_through' => $validThrough,
                    'sort' => $sort,
                ]);
                $vacancy->storeTranslations('title', $title);
                $vacancy->storeTranslations('summary', $summary);
                $vacancy->storeTranslations('description', $description);
                $vacancy->storeTranslations('meta_title', $metaTitle);
                $vacancy->storeTranslations('meta_description', $metaDescription);

                foreach ($locales as $locale) {
                    $vacancy->setAttribute("slug_{$locale}", $slugs[$locale] ?? null);
                }

                if ($publish && ! $vacancy->isComplete()) {
                    throw ValidationException::withMessages(['is_published' => __('careers.admin.errors.incomplete')]);
                }

                $vacancy->is_published = $publish;

                if ($publish && $vacancy->published_at === null) {
                    $vacancy->published_at = now();
                }

                $vacancy->save();

                return $vacancy;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel save took one of the slugs between the check and the insert.
            throw ValidationException::withMessages(['slug.uz' => __('validation.unique', ['attribute' => __('careers.admin.fields.slug')])]);
        }
    }

    /**
     * Slugs per locale, unique across the three columns.
     *
     * @param  array<string, string|null>  $typed
     * @param  array<string, string>  $title
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function slugs(?Vacancy $vacancy, array $typed, array $title): array
    {
        $ownId = $vacancy?->exists ? $vacancy->id : null;
        $used = [];
        $slugs = [];
        $errors = [];

        foreach (config('app.supported_locales') as $locale) {
            $input = Slug::make((string) ($typed[$locale] ?? ''));
            $stored = $vacancy?->exists ? $vacancy->getAttribute("slug_{$locale}") : null;

            // A blank input never changes a live URL: an existing vacancy keeps its stored slug.
            if ($input === '' && is_string($stored) && $stored !== '') {
                $slugs[$locale] = $stored;
                $used[] = $stored;

                continue;
            }

            $generated = $input === '';
            $base = $generated ? Slug::make($title[$locale] ?? '') : $input;

            if ($base === '') {
                if ($locale === 'uz') {
                    $errors['slug.uz'] = __('validation.required', ['attribute' => __('careers.admin.fields.slug')]);
                }

                $slugs[$locale] = null;

                continue;
            }

            $base = rtrim(substr($base, 0, Vacancy::SLUG_MAX - 5), '-');
            $taken = fn (string $slug): bool => in_array($slug, $used, true) || $this->taken($slug, $ownId);

            if (! $generated && $taken($base)) {
                $errors["slug.{$locale}"] = __('validation.unique', ['attribute' => __('careers.admin.fields.slug').' ('.strtoupper($locale).')']);

                continue;
            }

            $slugs[$locale] = $generated ? Slug::unique($base, $taken) : $base;
            $used[] = $slugs[$locale];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $slugs;
    }

    /**
     * Whether another vacancy uses the slug in any of the three columns.
     */
    private function taken(string $slug, ?int $ignoreId): bool
    {
        return Vacancy::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($slug): void {
                foreach (config('app.supported_locales') as $locale) {
                    $query->orWhere("slug_{$locale}", $slug);
                }
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function texts(array $values, bool $multiline): array
    {
        $clean = [];

        foreach (config('app.supported_locales') as $locale) {
            $clean[$locale] = PlainText::clean(is_string($values[$locale] ?? null) ? $values[$locale] : '', $multiline);
        }

        return $clean;
    }

    /**
     * One list per locale (an array of lines, or a text with one item per line).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>|null
     */
    private function lists(array $values): ?array
    {
        $lists = [];

        foreach (config('app.supported_locales') as $locale) {
            $raw = $values[$locale] ?? [];
            $lines = is_string($raw) ? preg_split('/\R/u', $raw) : (array) $raw;
            $items = [];

            foreach ($lines as $line) {
                $item = PlainText::clean(is_string($line) ? $line : '');

                if ($item !== '') {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $lists[$locale] = $items;
            }
        }

        return $lists === [] ? null : $lists;
    }

    private function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * @param  array<string, string>  $title
     * @param  array<string, string>  $summary
     * @param  array<string, string>  $description
     * @param  array<string, string>  $metaTitle
     * @param  array<string, string>  $metaDescription
     * @param  array<string, list<string>>|null  $requirements
     * @param  array<string, list<string>>|null  $offer
     *
     * @throws ValidationException
     */
    private function check(array $title, array $summary, array $description, array $metaTitle, array $metaDescription, ?array $requirements, ?array $offer): void
    {
        $errors = [];
        $rules = [
            'title' => [$title, Vacancy::TITLE_MAX],
            'summary' => [$summary, Vacancy::SUMMARY_MAX],
            'description' => [$description, Vacancy::DESCRIPTION_MAX],
            'meta_title' => [$metaTitle, 70],
            'meta_description' => [$metaDescription, 200],
        ];

        foreach ($rules as $field => [$values, $max]) {
            foreach ($values as $locale => $text) {
                $attribute = __("careers.admin.fields.{$field}").' ('.strtoupper($locale).')';

                if ($locale === 'uz' && $text === '' && in_array($field, ['title', 'summary', 'description'], true)) {
                    $errors["{$field}.{$locale}"] = __('validation.required', ['attribute' => $attribute]);
                } elseif (mb_strlen($text) > $max) {
                    $errors["{$field}.{$locale}"] = __('validation.max.string', ['attribute' => $attribute, 'max' => $max]);
                } elseif (BrandSafeText::violates($text, BrandSafeText::CAREERS)) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => $attribute]);
                }
            }
        }

        foreach (['requirements' => $requirements, 'offer' => $offer] as $field => $lists) {
            foreach ((array) $lists as $locale => $items) {
                $attribute = __("careers.admin.fields.{$field}").' ('.strtoupper($locale).')';

                if (count($items) > Vacancy::LIST_MAX_ITEMS) {
                    $errors["{$field}.{$locale}"] = __('validation.max.array', ['attribute' => $attribute, 'max' => Vacancy::LIST_MAX_ITEMS]);
                } elseif (collect($items)->contains(fn (string $item): bool => mb_strlen($item) > Vacancy::LIST_ITEM_MAX)) {
                    $errors["{$field}.{$locale}"] = __('validation.max.string', ['attribute' => $attribute, 'max' => Vacancy::LIST_ITEM_MAX]);
                } elseif (collect($items)->contains(fn (string $item): bool => BrandSafeText::violates($item, BrandSafeText::CAREERS))) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => $attribute]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
