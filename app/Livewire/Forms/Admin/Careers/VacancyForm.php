<?php

namespace App\Livewire\Forms\Admin\Careers;

use App\Enums\EmploymentType;
use App\Enums\Region;
use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use App\Rules\BrandSafeText;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the vacancy editor (plan 31): selects, sort, valid
 * through date, publish switch, and per locale the title, slug, summary,
 * description, requirements / offer (one item per line) and SEO texts.
 * uz is required, ru and en are needed to publish (SaveVacancy and
 * PublishVacancy enforce it). Public texts are brand-safe. Saved through SaveVacancy.
 */
class VacancyForm extends Form
{
    public string $department = '';

    public string $location = '';

    public string $employment_type = '';

    /** @var array<string, string> */
    public array $title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $slug = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $summary = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $description = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> one item per line */
    public array $requirements = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> one item per line */
    public array $offer = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $is_published = false;

    public string $valid_through = '';

    public int|string $sort = 0;

    public function setVacancy(?Vacancy $vacancy): void
    {
        $this->resetValidation();

        if ($vacancy === null) {
            $this->reset();

            return;
        }

        $this->department = $vacancy->department->value;
        $this->location = $vacancy->location->value;
        $this->employment_type = $vacancy->employment_type->value;
        $this->is_published = $vacancy->is_published;
        $this->valid_through = $vacancy->valid_through?->toDateString() ?? '';
        $this->sort = $vacancy->sort;

        foreach (['title', 'summary', 'description', 'meta_title', 'meta_description'] as $field) {
            $this->{$field} = $this->perLocale(fn (string $locale): string => (string) $vacancy->getTranslation($field, $locale, false));
        }

        foreach (['requirements', 'offer'] as $field) {
            $this->{$field} = $this->perLocale(fn (string $locale): string => implode("\n", $vacancy->listFor($field, $locale)));
        }

        $this->slug = $this->perLocale(fn (string $locale): string => (string) $vacancy->slugFor($locale));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'department' => ['required', Rule::enum(VacancyDepartment::class)],
            'location' => ['required', Rule::enum(Region::class)],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'is_published' => ['boolean'],
            'valid_through' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'integer', 'between:0,65535'],
        ];

        foreach (config('app.supported_locales') as $locale) {
            $required = $locale === 'uz' ? 'required' : 'nullable';
            $rules["title.{$locale}"] = [$required, 'string', 'max:'.Vacancy::TITLE_MAX, new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["slug.{$locale}"] = ['nullable', 'string', 'max:'.Vacancy::SLUG_MAX];
            $rules["summary.{$locale}"] = [$required, 'string', 'max:'.Vacancy::SUMMARY_MAX, new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["description.{$locale}"] = [$required, 'string', 'max:'.Vacancy::DESCRIPTION_MAX, new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["requirements.{$locale}"] = ['nullable', 'string', 'max:'.(Vacancy::LIST_MAX_ITEMS * (Vacancy::LIST_ITEM_MAX + 2)), new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["offer.{$locale}"] = ['nullable', 'string', 'max:'.(Vacancy::LIST_MAX_ITEMS * (Vacancy::LIST_ITEM_MAX + 2)), new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["meta_title.{$locale}"] = ['nullable', 'string', 'max:70', new BrandSafeText(BrandSafeText::CAREERS)];
            $rules["meta_description.{$locale}"] = ['nullable', 'string', 'max:200', new BrandSafeText(BrandSafeText::CAREERS)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (['department', 'location', 'employment_type', 'valid_through', 'sort'] as $field) {
            $attributes[$field] = __("careers.admin.fields.{$field}");
        }

        foreach (config('app.supported_locales') as $locale) {
            foreach (['title', 'slug', 'summary', 'description', 'requirements', 'offer', 'meta_title', 'meta_description'] as $field) {
                $attributes["{$field}.{$locale}"] = __("careers.admin.fields.{$field}").' ('.strtoupper($locale).')';
            }
        }

        return $attributes;
    }

    /**
     * Input for SaveVacancy.
     *
     * @return array<string, mixed>
     */
    public function vacancyData(): array
    {
        return [
            'department' => $this->department,
            'location' => $this->location,
            'employment_type' => $this->employment_type,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'offer' => $this->offer,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'is_published' => $this->is_published,
            'valid_through' => $this->valid_through === '' ? null : $this->valid_through,
            'sort' => (int) $this->sort,
        ];
    }

    /**
     * @param  callable(string): string  $value
     * @return array<string, string>
     */
    private function perLocale(callable $value): array
    {
        return collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => $value($locale)])
            ->all();
    }
}
