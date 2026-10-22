<?php

namespace App\Livewire\Forms\Admin\Brands;

use App\Models\Brand;
use App\Rules\HttpsLink;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * State and validation of the brand form. Saved through SaveBrand and
 * UpdateCatalogImage (logo).
 */
class BrandForm extends Form
{
    public string $name = '';

    public string $slug = '';

    /** @var array<string, string> */
    public array $description = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $website = '';

    public bool $is_active = true;

    public string $sort = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    /** @var array<string, string> */
    public array $logo_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $remove_logo = false;

    public function setBrand(Brand $brand): void
    {
        $brand->loadMissing('media');

        $this->name = $brand->name;
        $this->slug = $brand->slug;
        $description = $brand->getTranslations('description');
        $this->description = collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale): array => [$locale => (string) ($description[$locale] ?? '')])->all();
        $this->website = (string) $brand->website;
        $this->is_active = $brand->is_active;
        $this->sort = (string) $brand->sort;
        $this->logo = null;
        $this->logo_alt = $brand->imageAlts();
        $this->remove_logo = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($this->component->brand?->id)],
            'description.*' => ['nullable', 'string', 'max:20000'],
            'website' => ['nullable', 'string', new HttpsLink],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'logo_alt.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'name' => __('admin.catalog.name'),
            'slug' => __('admin.catalog.slug'),
            'website' => __('admin.brands.fields.website'),
            'sort' => __('admin.catalog.sort'),
            'logo' => __('admin.brands.fields.logo'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $attributes["description.{$locale}"] = __('admin.catalog.description').' ('.strtoupper($locale).')';
            $attributes["logo_alt.{$locale}"] = __('admin.image_upload.alt').' ('.strtoupper($locale).')';
        }

        return $attributes;
    }

    /**
     * Input for SaveBrand.
     *
     * @return array{name: string, slug: string, description: array<string, string>, website: string|null, is_active: bool, sort: int|null}
     */
    public function brandData(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'website' => filled($this->website) ? $this->website : null,
            'is_active' => $this->is_active,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
        ];
    }
}
