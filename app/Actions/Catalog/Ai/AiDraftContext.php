<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\ProductType;
use App\Models\AiProductDraft;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiProduct\AiSpecParser;
use App\Support\Content\AiProduct\ProductAiDraft;
use Illuminate\Support\Facades\Log;

/**
 * Data blocks of the wizard prompts (plan 32 §2.8, PW-25): an explicit list
 * of fields, never a model dump. No price of any level, no cost, stock,
 * 1C or sync data ever goes into a prompt.
 */
class AiDraftContext
{
    public function __construct(private readonly AiSpecParser $specs) {}

    /**
     * <main_info>: category path, brand, model, SKU and type (English).
     *
     * @return array<string, string>
     */
    public function mainInfo(AiProductDraft $draft): array
    {
        $info = $draft->input['info'] ?? [];
        $categoryId = (int) ($info['category_id'] ?? 0);
        $brandId = (int) ($info['brand_id'] ?? 0);

        return array_filter([
            'category' => $categoryId > 0 && CategoryTree::find($categoryId) !== null ? CategoryTree::path($categoryId, 'en') : '',
            'brand' => $brandId > 0 ? (string) Brand::query()->whereKey($brandId)->value('name') : '',
            'model' => $this->text($info['model'] ?? '', 100),
            'sku' => $this->text($info['sku'] ?? '', 64),
            'type' => ProductType::tryFrom((string) ($info['type'] ?? ''))?->value ?? '',
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * <attributes>: the category chain's attributes with their option ids.
     *
     * @return list<array{code: string, name: string, unit: string, type: string, required: bool, options: list<string>, current?: string}>
     */
    public function attributes(AiProductDraft $draft, ?Product $product = null): array
    {
        $categoryId = (int) ($draft->input['info']['category_id'] ?? 0);
        $current = [];

        if ($product !== null) {
            foreach ($product->attributeValues()->with(['attribute', 'option'])->get() as $value) {
                $current[$value->attribute_id][] = $value->displayValue();
            }
        }

        $optionsMax = (int) config('chatbot.ai.product.options_max', 50);

        return $this->capAttributes(array_map(function (ResolvedAttribute $row) use ($current, $optionsMax): array {
            $attribute = $row->attribute;
            $item = [
                'code' => $attribute->code,
                'name' => $this->text($attribute->getTranslation('name', 'en', true), 120),
                'unit' => (string) $attribute->unit,
                'type' => $attribute->type->value,
                'required' => $row->required,
                'options' => $attribute->type->hasOptions()
                    ? $attribute->options->take($optionsMax)->map(fn ($option): string => $option->id.': '.$this->text($option->getTranslation('value', 'en', true), 80))->values()->all()
                    : [],
            ];

            if (isset($current[$attribute->id])) {
                $item['current'] = $this->text(implode(', ', array_filter($current[$attribute->id])), 200);
            }

            return $item;
        }, $this->specs->attributes($categoryId > 0 ? $categoryId : null)));
    }

    /**
     * Keep the <attributes> block under attributes_chars (L6): options are
     * trimmed first (the longest lists halved), then optional attributes
     * dropped from the end, then any. Only counts are logged.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function capAttributes(array $items): array
    {
        $max = max(2000, (int) config('chatbot.ai.product.attributes_chars', 24000));
        $size = fn (array $list): int => mb_strlen((string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $options = 0;
        $dropped = 0;

        while ($size($items) > $max) {
            $longest = null;

            foreach ($items as $index => $item) {
                if (count($item['options']) > 0 && ($longest === null || count($item['options']) > count($items[$longest]['options']))) {
                    $longest = $index;
                }
            }

            if ($longest === null) {
                break;
            }

            $keep = intdiv(count($items[$longest]['options']), 2);
            $options += count($items[$longest]['options']) - $keep;
            $items[$longest]['options'] = array_slice($items[$longest]['options'], 0, $keep);
        }

        while ($items !== [] && $size($items) > $max) {
            $optional = array_keys(array_filter($items, fn (array $item): bool => ! $item['required']));
            $index = $optional !== [] ? end($optional) : array_key_last($items);
            unset($items[$index]);
            $items = array_values($items);
            $dropped++;
        }

        if ($options > 0 || $dropped > 0) {
            Log::info('ai.draft.attributes_capped', ['options_removed' => $options, 'attributes_removed' => $dropped, 'attributes_kept' => count($items)]);
        }

        return $items;
    }

    /**
     * <current>: improve mode, the product's texts (each ≤ current_max).
     *
     * @return array<string, array<string, string>>
     */
    public function current(Product $product): array
    {
        $max = (int) config('chatbot.ai.product.current_max', 1500);
        $current = [];

        foreach (ProductAiDraft::TEXT_FIELDS as $field) {
            foreach ($product->getTranslations($field) as $locale => $value) {
                $text = $this->text(is_string($value) ? strip_tags($value) : '', $max);

                if ($text !== '' && in_array($locale, config('app.supported_locales'), true)) {
                    $current[$field][$locale] = $text;
                }
            }
        }

        return $current;
    }

    /**
     * Facts built from the current product (improve without new photos).
     *
     * @return array<string, mixed>
     */
    public function factsFromProduct(Product $product): array
    {
        $product->loadMissing('brand');

        return [
            'device_type' => $this->text(CategoryTree::name($product->category_id, 'en'), 80),
            'brand' => $this->text((string) $product->brand?->name, 60),
            'models' => array_values(array_filter([$this->text((string) $product->model, 60)])),
            'part_numbers' => array_values(array_filter([$this->text($product->sku, 60)])),
            'features' => [],
            'accessories' => [],
            'views' => [],
            'main_image' => 1,
            'unsure' => [],
        ];
    }

    private function text(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cf}/u', '', AiAnswerParser::cleanCharacters((string) $value)))) : '';

        return mb_substr($text, 0, $max);
    }
}
