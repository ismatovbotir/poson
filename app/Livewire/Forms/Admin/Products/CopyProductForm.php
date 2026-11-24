<?php

namespace App\Livewire\Forms\Admin\Products;

use App\Models\Product;
use App\Support\Catalog\ProductTextRules;
use App\Support\Content\ContentLimits;
use Livewire\Form;

/**
 * State and validation of the "Copy product" dialog (plan 39). Holds only the
 * new SKU and the three names: never prices, the type or other source data.
 * Saved through DuplicateProduct.
 */
class CopyProductForm extends Form
{
    public string $sku = '';

    /** @var array<string, string> */
    public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

    /**
     * Prefill from the source: its SKU and its names with a copy suffix
     * (only for locales that have a name; cut to fit the name limit).
     */
    public function setSource(Product $source): void
    {
        $this->resetValidation();
        $this->sku = (string) $source->sku;

        foreach (config('app.supported_locales') as $locale) {
            $name = trim((string) ($source->getTranslation('name', $locale, false) ?? ''));
            $suffix = __('admin.products.copy.suffix', [], $locale);

            $this->name[$locale] = $name === ''
                ? ''
                : mb_substr($name, 0, max(0, ContentLimits::SHORT_TEXT_MAX - mb_strlen($suffix))).$suffix;
        }
    }

    public function clear(): void
    {
        $this->reset('sku', 'name');
        $this->resetValidation();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['sku' => ['required', 'string', 'max:64']];

        foreach (ProductTextRules::rules() as $key => $rule) {
            if ($key === 'name' || str_starts_with($key, 'name.')) {
                $rules[$key] = $rule;
            }
        }

        return $rules;
    }
}
