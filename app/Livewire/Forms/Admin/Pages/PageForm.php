<?php

namespace App\Livewire\Forms\Admin\Pages;

use App\Enums\PageTemplate;
use App\Models\Page;
use App\Rules\BrandSafeText;
use App\Support\Slug;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * State and validation of the page form. The component owns the page model
 * ($this->component->page); saving goes through SavePage and
 * UpdateCatalogImage (cover). The slug is never editable (SavePage generates
 * it once); the template of system pages is shown read-only and never sent
 * to SavePage as a change.
 */
class PageForm extends Form
{
    /** @var array<string, string> */
    public array $title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $body = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

    /**
     * Stored slug, display only: never validated nor sent to the action
     * (generated once from the en title, else uz, then fixed).
     */
    public string $slug = '';

    public string $template = 'default';

    public string $sort = '';

    public bool $is_active = true;

    public bool $needs_review = false;

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    /** @var array<string, string> */
    public array $image_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $remove_image = false;

    public function setPage(Page $page): void
    {
        $page->loadMissing('media');

        $this->title = $this->locales($page->getTranslations('title'));
        $this->body = $this->locales($page->getTranslations('body'));
        $this->meta_title = $this->locales($page->getTranslations('meta_title'));
        $this->meta_description = $this->locales($page->getTranslations('meta_description'));
        $this->slug = $page->slug;
        $this->template = $page->template->value;
        $this->sort = (string) $page->sort;
        $this->is_active = $page->is_active;
        $this->needs_review = $page->needs_review;
        $this->image = null;
        $this->image_alt = $page->imageAlts();
        $this->remove_image = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $system = (bool) $this->component->page?->is_system;

        return [
            'title.uz' => ['required', 'string', 'max:255', new BrandSafeText],
            'title.ru' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'title.en' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'body.*' => ['nullable', 'string', 'max:'.Page::BODY_MAX],
            'meta_title.*' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'meta_description.*' => ['nullable', 'string', 'max:500', new BrandSafeText],
            'template' => $system ? ['nullable'] : ['required', Rule::enum(PageTemplate::class)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
            'needs_review' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'template' => __('admin.pages.fields.template'),
            'sort' => __('admin.catalog.sort'),
            'image' => __('admin.pages.fields.cover'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';

            foreach (['title', 'body', 'meta_title', 'meta_description'] as $field) {
                $attributes["{$field}.{$locale}"] = __("admin.pages.fields.{$field}").$suffix;
            }

            $attributes["image_alt.{$locale}"] = __('admin.image_upload.alt').$suffix;
        }

        return $attributes;
    }

    /**
     * Slug SavePage generates on create (live preview while the record has
     * none). A system slug gets the suffix SavePage gives it ("about" →
     * "about-2"); other taken slugs are not looked up (no query per render).
     */
    public function autoSlug(): string
    {
        $base = Slug::make(filled($this->title['en'] ?? '') ? $this->title['en'] : ($this->title['uz'] ?? ''));

        return $base === '' ? '' : Slug::unique($base, Page::isSystemSlug(...));
    }

    public function templateEnum(): PageTemplate
    {
        return PageTemplate::tryFrom($this->template) ?? PageTemplate::Default;
    }

    /**
     * Input for SavePage.
     *
     * @return array{title: array<string, string>, body: array<string, string>, meta_title: array<string, string>, meta_description: array<string, string>, template: string, sort: int|null, is_active: bool, needs_review: bool}
     */
    public function pageData(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'template' => $this->template,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
            'is_active' => $this->is_active,
            'needs_review' => $this->needs_review,
        ];
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
