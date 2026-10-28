<?php

namespace App\Livewire\Forms\Admin\Brands;

use App\Models\Brand;
use App\Rules\BrandSafeText;
use App\Rules\HttpsLink;
use App\Support\Slug;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * State and validation of the brand form. Saved through SaveBrand and
 * UpdateCatalogImage (logo).
 */
class BrandForm extends Form
{
    public string $name = '';

    /**
     * Stored slug, display only: never validated nor sent to the action
     * (generated once from the name, then fixed).
     */
    public string $slug = '';

    /** @var array<string, string> */
    public array $description = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

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
        $this->description = $this->locales($description);
        $this->meta_title = $this->locales($brand->getTranslations('meta_title'));
        $this->meta_description = $this->locales($brand->getTranslations('meta_description'));
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
            'description.*' => ['nullable', 'string', 'max:20000', new BrandSafeText],
            'meta_title.*' => ['nullable', 'string', 'max:'.Brand::META_TITLE_MAX, new BrandSafeText],
            'meta_description.*' => ['nullable', 'string', 'max:'.Brand::META_DESCRIPTION_MAX, new BrandSafeText],
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
            'website' => __('admin.brands.fields.website'),
            'sort' => __('admin.catalog.sort'),
            'logo' => __('admin.brands.fields.logo'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $attributes["description.{$locale}"] = __('admin.catalog.description').' ('.strtoupper($locale).')';
            $attributes["meta_title.{$locale}"] = __('admin.catalog.meta_title').' ('.strtoupper($locale).')';
            $attributes["meta_description.{$locale}"] = __('admin.catalog.meta_description').' ('.strtoupper($locale).')';
            $attributes["logo_alt.{$locale}"] = __('admin.image_upload.alt').' ('.strtoupper($locale).')';
        }

        return $attributes;
    }

    /**
     * Slug SaveBrand generates from the name on create (live preview while the record has none).
     */
    public function autoSlug(): string
    {
        return Slug::make($this->name);
    }

    /**
     * Input for SaveBrand.
     *
     * @return array{name: string, description: array<string, string>, meta_title: array<string, string>, meta_description: array<string, string>, website: string|null, is_active: bool, sort: int|null}
     */
    public function brandData(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'website' => filled($this->website) ? $this->website : null,
            'is_active' => $this->is_active,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function locales(array $values): array
    {
        return collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale): array => [$locale => (string) ($values[$locale] ?? '')])->all();
    }
}
