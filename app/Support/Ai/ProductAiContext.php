<?php

namespace App\Support\Ai;

use App\Enums\AttributeType;
use App\Enums\ProductType;
use App\Livewire\Forms\Admin\Products\ProductForm;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\CategoryTree;

/**
 * AI context of a product, from the unsaved product form (fromForm) or a
 * saved product (fromProduct, e.g. for its FAQ). Facts only: names, type,
 * category path, brand, model, warranty and the filled-in specs (skipped
 * rows and empty values left out). Never prices or stock (brand rule: the
 * texts don't talk about price).
 */
final class ProductAiContext
{
    public static function fromForm(ProductForm $form, ?Product $product = null): AiContext
    {
        $categoryId = filled($form->category_id) ? (int) $form->category_id : null;
        $brandId = filled($form->brand_id) ? (int) $form->brand_id : null;

        $facts = self::localized('Name', $form->name);
        $facts['Product type'] = ProductType::tryFrom($form->type)?->value ?? '';
        $facts['Model'] = $form->model;
        $facts['SKU'] = $form->sku;

        if (ctype_digit($form->warranty_months) && (int) $form->warranty_months > 0) {
            $facts['Official warranty, months'] = $form->warranty_months;
        }

        $facts += self::localized('Short description', $form->short_description);

        return AiContext::fromArray([
            'name' => self::first($form->name),
            'category' => $categoryId !== null && CategoryTree::find($categoryId) !== null ? CategoryTree::path($categoryId, 'en') : '',
            'brand' => $brandId !== null ? (string) Brand::query()->whereKey($brandId)->value('name') : '',
            'specs' => self::specs($form->specs),
            'facts' => array_filter($facts, fn (string $value): bool => trim($value) !== ''),
            'subject_type' => $product?->exists ? $product->getMorphClass() : null,
            'subject_id' => $product?->exists ? $product->id : null,
        ]);
    }

    /**
     * A saved product (FAQ editor): names, type, category, brand and model.
     */
    public static function fromProduct(Product $product): AiContext
    {
        $product->loadMissing('brand');

        $facts = self::localized('Name', $product->getTranslations('name'));
        $facts['Product type'] = $product->type->value;
        $facts['Model'] = (string) $product->model;
        $facts += self::localized('Short description', $product->getTranslations('short_description'));

        return AiContext::fromArray([
            'name' => self::first($product->getTranslations('name')),
            'category' => CategoryTree::find($product->category_id) !== null ? CategoryTree::path($product->category_id, 'en') : '',
            'brand' => (string) $product->brand?->name,
            'facts' => array_filter($facts, fn (string $value): bool => trim($value) !== ''),
            'subject_type' => $product->getMorphClass(),
            'subject_id' => $product->id,
        ]);
    }

    /**
     * "- Print resolution: 203 dpi" lines of a saved product's spec values
     * (image alt, plan 12): in English, one line per attribute (multiselect
     * options joined), in the saved order, skipped attributes left out.
     * Two queries (values with attribute and option, skips).
     */
    public static function savedSpecs(Product $product): string
    {
        $skipped = $product->attributeSkips()->pluck('attribute_id')->map(fn (mixed $id): int => (int) $id)->all();

        $values = $product->attributeValues()
            ->with(['attribute', 'option'])
            ->whereNotIn('attribute_id', $skipped)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $lines = [];

        foreach ($values->groupBy('attribute_id') as $rows) {
            /** @var ProductAttributeValue $first */
            $first = $rows->first();
            $attribute = $first->getRelationValue('attribute');

            if (! $attribute instanceof Attribute) {
                continue;
            }

            $value = match ($attribute->type) {
                AttributeType::Text => self::first($first->getTranslations('value_text'), preferred: 'en'),
                AttributeType::Number => $first->numberForDisplay() !== null ? $first->numberForDisplay().($attribute->unit ? ' '.$attribute->unit : '') : '',
                AttributeType::Boolean => $first->value_bool === null ? '' : ($first->value_bool ? 'yes' : 'no'),
                AttributeType::Select, AttributeType::Multiselect => $rows
                    ->map(fn (ProductAttributeValue $row): string => (string) $row->getRelationValue('option')?->getTranslation('value', 'en'))
                    ->filter()
                    ->implode(', '),
            };

            if (trim($value) !== '') {
                $lines[] = '- '.$attribute->getTranslation('name', 'en').': '.AiContext::clean($value, 300);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * "- Print resolution: 203 dpi" lines of the filled-in, not skipped rows.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function specs(array $rows): string
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => ! ($row['skipped'] ?? false)));

        if ($rows === []) {
            return '';
        }

        $attributes = Attribute::query()->whereIn('id', array_map(fn (array $row): int => (int) $row['attribute_id'], $rows))->get()->keyBy('id');
        $optionIds = [];

        foreach ($rows as $row) {
            $optionIds = [...$optionIds, (int) ($row['option'] ?? 0), ...array_map('intval', (array) ($row['options'] ?? []))];
        }

        $options = AttributeOption::query()->whereIn('id', array_filter($optionIds))->get()->keyBy('id');
        $lines = [];

        foreach ($rows as $row) {
            $attribute = $attributes->get((int) $row['attribute_id']);

            if ($attribute === null || ! ProductForm::rowHasValue($row, $attribute->type)) {
                continue;
            }

            $value = match ($attribute->type) {
                AttributeType::Text => self::first((array) ($row['text'] ?? []), preferred: 'en'),
                AttributeType::Number => trim((string) $row['number']).($attribute->unit ? ' '.$attribute->unit : ''),
                AttributeType::Boolean => in_array((string) $row['bool'], ['1', 'true'], true) ? 'yes' : 'no',
                AttributeType::Select => (string) $options->get((int) $row['option'])?->getTranslation('value', 'en'),
                AttributeType::Multiselect => collect((array) $row['options'])
                    ->map(fn (mixed $id): string => (string) $options->get((int) $id)?->getTranslation('value', 'en'))
                    ->filter()
                    ->implode(', '),
            };

            if (trim($value) !== '') {
                $lines[] = '- '.$attribute->getTranslation('name', 'en').': '.AiContext::clean($value, 300);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * First non-empty value, uz → ru → en (or the preferred locale first).
     *
     * @param  array<string, mixed>  $values
     */
    public static function first(array $values, string $preferred = 'uz'): string
    {
        foreach (array_unique([$preferred, ...config('app.supported_locales')]) as $locale) {
            $value = AiContext::clean($values[$locale] ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * "Name (uz)" => …, one fact per filled-in locale.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function localized(string $label, array $values): array
    {
        $facts = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = AiContext::clean($values[$locale] ?? '');

            if ($value !== '') {
                $facts["{$label} ({$locale})"] = $value;
            }
        }

        return $facts;
    }
}
