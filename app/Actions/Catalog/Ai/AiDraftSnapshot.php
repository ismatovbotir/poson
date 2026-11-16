<?php

namespace App\Actions\Catalog\Ai;

use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Support\Content\AiProduct\ProductAiDraft;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Field snapshot of a product for the improve conflict check (plan 32 §14
 * plan change 4, PW-21): one hash per AI-changeable text field, per step 1
 * field, one for the spec rows and skips, one for the image set (ids, order,
 * alts). Taken when the wizard opens, recomputed under the product lock at
 * apply. Prices are hashed but only compared when the user changed the price
 * (a price sync in between is not a conflict).
 */
final class AiDraftSnapshot
{
    public const INFO = ['category_id', 'brand_id', 'model', 'sku', 'type', 'sale_mode', 'price'];

    /**
     * @return array<string, string>
     */
    public static function of(Product $product): array
    {
        $hashes = [];

        foreach (ProductAiDraft::TEXT_FIELDS as $field) {
            $hashes["text:{$field}"] = self::hash($product->getTranslations($field));
        }

        foreach (self::INFO as $field) {
            $value = $product->getRawOriginal($field);
            $hashes["info:{$field}"] = self::hash($value === null ? null : (string) $value);
        }

        $values = ProductAttributeValue::query()->where('product_id', $product->id)->orderBy('attribute_id')->orderBy('id')
            ->get(['attribute_id', 'attribute_option_id', 'value_number', 'value_bool', 'value_text', 'is_key'])
            ->map(fn (ProductAttributeValue $value): array => [
                $value->attribute_id, $value->attribute_option_id, (string) $value->getRawOriginal('value_number'),
                $value->getRawOriginal('value_bool'), (string) $value->getRawOriginal('value_text'), (bool) $value->is_key,
            ])->all();
        $skips = ProductAttributeSkip::query()->where('product_id', $product->id)->orderBy('attribute_id')->pluck('attribute_id')->all();
        $hashes['specs'] = self::hash([$values, $skips]);

        $media = Media::query()->where('model_type', $product->getMorphClass())->where('model_id', $product->id)
            ->where('collection_name', Product::IMAGES)->orderBy('order_column')->get()
            ->map(fn (Media $media): array => [$media->id, $media->getCustomProperty('alt')])->all();
        $hashes['images'] = self::hash($media);

        return $hashes;
    }

    /**
     * Snapshot keys that changed since $before.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $now
     * @return list<string>
     */
    public static function changed(array $before, array $now): array
    {
        $changed = [];

        foreach ($now as $key => $hash) {
            if (($before[$key] ?? null) !== $hash) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private static function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
