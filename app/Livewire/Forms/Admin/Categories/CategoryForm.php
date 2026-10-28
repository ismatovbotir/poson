<?php

namespace App\Livewire\Forms\Admin\Categories;

use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\Slug;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * State and validation of the category form. The component owns the
 * category model ($this->component->category); saving goes through
 * SaveCategory, SyncCategoryAttributes and UpdateCatalogImage.
 */
class CategoryForm extends Form
{
    /** @var array<string, string> */
    public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $description = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

    /**
     * Stored slug, display only: never validated nor sent to the action
     * (generated once from the en name, else uz, then fixed).
     */
    public string $slug = '';

    /**
     * Parent category id as a string ('' = root), as the select sends it.
     */
    public string $parent_id = '';

    public string $icon = '';

    public string $sort = '';

    public bool $is_active = true;

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    /** @var array<string, string> */
    public array $image_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $remove_image = false;

    /**
     * The category's own attributes, in order.
     *
     * @var list<array{attribute_id: int, is_required: bool, is_filterable: bool}>
     */
    public array $specs = [];

    public function setCategory(Category $category): void
    {
        $category->loadMissing(['attributes', 'media']);

        $this->name = $this->locales($category->getTranslations('name'));
        $this->description = $this->locales($category->getTranslations('description'));
        $this->meta_title = $this->locales($category->getTranslations('meta_title'));
        $this->meta_description = $this->locales($category->getTranslations('meta_description'));
        $this->slug = $category->slug;
        $this->parent_id = (string) ($category->parent_id ?? '');
        $this->icon = (string) $category->icon;
        $this->sort = (string) $category->sort;
        $this->is_active = $category->is_active;
        $this->image = null;
        $this->image_alt = $category->imageAlts();
        $this->remove_image = false;
        $this->specs = $category->getRelationValue('attributes')
            ->map(fn ($attribute): array => [
                'attribute_id' => $attribute->id,
                'is_required' => $attribute->pivot->is_required,
                'is_filterable' => $attribute->pivot->is_filterable,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $categoryId = $this->component->category?->id;

        return [
            'name.uz' => ['required', 'string', 'max:255'],
            'name.ru' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'description.*' => ['nullable', 'string', 'max:20000'],
            'meta_title.*' => ['nullable', 'string', 'max:255'],
            'meta_description.*' => ['nullable', 'string', 'max:500'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id'),
                function (string $attribute, mixed $value, Closure $fail) use ($categoryId): void {
                    if (filled($value) && $error = CategoryTree::parentError($categoryId, (int) $value)) {
                        $fail($error);
                    }
                },
            ],
            'icon' => ['nullable', Rule::in(Category::ICONS)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt.*' => ['nullable', 'string', 'max:255'],
            'specs' => ['array', 'max:200'],
            'specs.*.attribute_id' => ['required', 'integer', 'distinct', Rule::exists('attributes', 'id')],
            'specs.*.is_required' => ['boolean'],
            'specs.*.is_filterable' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'parent_id' => __('admin.categories.fields.parent'),
            'icon' => __('admin.categories.fields.icon'),
            'sort' => __('admin.catalog.sort'),
            'image' => __('admin.categories.fields.image'),
            'specs.*.attribute_id' => __('admin.categories.attributes.attribute'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $attributes["name.{$locale}"] = __('admin.catalog.name').$suffix;
            $attributes["description.{$locale}"] = __('admin.catalog.description').$suffix;
            $attributes["meta_title.{$locale}"] = __('admin.catalog.meta_title').$suffix;
            $attributes["meta_description.{$locale}"] = __('admin.catalog.meta_description').$suffix;
            $attributes["image_alt.{$locale}"] = __('admin.image_upload.alt').$suffix;
        }

        return $attributes;
    }

    /**
     * Slug SaveCategory generates on create (live preview while the record has none).
     */
    public function autoSlug(): string
    {
        return Slug::make(filled($this->name['en'] ?? '') ? $this->name['en'] : ($this->name['uz'] ?? ''));
    }

    /**
     * Input for SaveCategory.
     *
     * @return array{parent_id: int|null, name: array<string, string>, description: array<string, string>, meta_title: array<string, string>, meta_description: array<string, string>, icon: string|null, sort: int|null, is_active: bool}
     */
    public function categoryData(): array
    {
        return [
            'parent_id' => filled($this->parent_id) ? (int) $this->parent_id : null,
            'name' => $this->name,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'icon' => filled($this->icon) ? $this->icon : null,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * Input for SyncCategoryAttributes.
     *
     * @return list<array{attribute_id: int, is_required: bool, is_filterable: bool}>
     */
    public function specRows(): array
    {
        return array_values(array_map(fn (array $row): array => [
            'attribute_id' => (int) $row['attribute_id'],
            'is_required' => (bool) ($row['is_required'] ?? false),
            'is_filterable' => (bool) ($row['is_filterable'] ?? false),
        ], $this->specs));
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function locales(array $values): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $result[$locale] = (string) ($values[$locale] ?? '');
        }

        return $result;
    }
}
