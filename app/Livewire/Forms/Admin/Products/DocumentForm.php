<?php

namespace App\Livewire\Forms\Admin\Products;

use App\Enums\DocumentType;
use App\Models\ProductDocument;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * State and validation of the add / edit document dialog. A document is a
 * file (pdf, zip, exe ≤ 20 MB) or an external https link. Saved through
 * SaveProductDocument.
 */
class DocumentForm extends Form
{
    /**
     * Document being edited (null = new).
     */
    public ?int $id = null;

    /** @var array<string, string> */
    public array $title = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $type = 'datasheet';

    /**
     * '' = every language.
     */
    public string $locale = '';

    /**
     * 'file' or 'url'.
     */
    public string $source = 'file';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $url = '';

    public function setDocument(?ProductDocument $document): void
    {
        $this->resetValidation();
        $this->file = null;

        if ($document === null) {
            $this->reset('id', 'title', 'type', 'locale', 'source', 'url');

            return;
        }

        $title = $document->getTranslations('title');
        $this->id = $document->id;
        $this->title = collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale): array => [$locale => (string) ($title[$locale] ?? '')])->all();
        $this->type = $document->type->value;
        $this->locale = (string) $document->locale;
        $this->source = $document->isFile() ? 'file' : 'url';
        $this->url = (string) $document->url;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $needsFile = $this->source === 'file' && $this->id === null;

        return [
            'title.uz' => ['required', 'string', 'max:255'],
            'title.ru' => ['nullable', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'locale' => ['nullable', Rule::in(config('app.supported_locales'))],
            'source' => ['required', Rule::in(['file', 'url'])],
            'file' => [
                Rule::requiredIf($needsFile),
                'nullable',
                'file',
                'extensions:'.implode(',', ProductDocument::EXTENSIONS),
                'mimes:'.implode(',', ProductDocument::EXTENSIONS),
                'max:'.ProductDocument::MAX_KILOBYTES,
            ],
            'url' => [Rule::requiredIf($this->source === 'url'), 'nullable', 'string', 'max:2048', 'url:https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'url.url' => __('admin.products.documents.errors.url_https'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'type' => __('admin.products.documents.type'),
            'locale' => __('admin.products.documents.locale'),
            'file' => __('admin.products.documents.file'),
            'url' => __('admin.products.documents.url'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $attributes["title.{$locale}"] = __('admin.products.documents.title_field').' ('.strtoupper($locale).')';
        }

        return $attributes;
    }

    /**
     * Input for SaveProductDocument.
     *
     * @return array{title: array<string, string>, type: DocumentType, locale: string|null, url: string|null}
     */
    public function documentData(): array
    {
        return [
            'title' => $this->title,
            'type' => DocumentType::from($this->type),
            'locale' => filled($this->locale) ? $this->locale : null,
            'url' => $this->source === 'url' && filled($this->url) ? $this->url : null,
        ];
    }
}
