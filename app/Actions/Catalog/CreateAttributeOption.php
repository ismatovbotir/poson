<?php

namespace App\Actions\Catalog;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Support\HexColor;
use App\Support\Site\HomeCache;
use Illuminate\Validation\ValidationException;

/**
 * Add a value to a select / multiselect attribute. Deduplicates on the uz
 * value (trimmed, case-insensitive): an existing option is returned instead
 * of creating a second one. Also used for inline "new value" in the product
 * form (3c), where the caller authorizes attributes.update and passes no hex.
 *
 * Plan 38: an optional display colour "#RRGGBB" is validated here too (RD-2,
 * even when a form is bypassed) and stored upper case. A dedupe hit never
 * wipes a stored colour; it only fills an empty one (RD-5).
 */
class CreateAttributeOption
{
    /**
     * @param  array<string, string|null>  $value  locale => text, uz required
     * @param  list<int>  $excludeIds  options not considered for deduplication
     * @param  string|null  $hex  display colour, null = none
     *
     * @throws ValidationException
     */
    public function handle(Attribute $attribute, array $value, ?int $sort = null, string $field = 'value', array $excludeIds = [], ?string $hex = null, string $hexField = 'hex_color'): AttributeOption
    {
        if (! $attribute->type->hasOptions()) {
            throw ValidationException::withMessages([$field => __('admin.attributes.errors.no_options_for_type')]);
        }

        $uz = trim((string) ($value['uz'] ?? ''));

        if ($uz === '') {
            throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => __('admin.attributes.options.value').' (UZ)'])]);
        }

        $color = self::color($hex, $hexField);

        $existing = $attribute->options()->whereNotIn('id', $excludeIds)->get()->first(
            fn (AttributeOption $option): bool => mb_strtolower(trim((string) $option->getTranslation('value', 'uz', false))) === mb_strtolower($uz),
        );

        if ($existing !== null) {
            if ($color !== null && $existing->hexColor() === null) {
                $existing->hex_color = $color;
                $existing->save();
                HomeCache::bump();
            }

            return $existing;
        }

        $option = new AttributeOption([
            'attribute_id' => $attribute->id,
            'sort' => $sort ?? (int) $attribute->options()->max('sort') + 1,
        ]);
        $option->hex_color = $color;
        $option->storeTranslations('value', $value)->save();

        if ($color !== null) {
            HomeCache::bump();
        }

        return $option;
    }

    /**
     * The normalized colour, null for none (null or blank); anything else that is
     * not "#RRGGBB" (also arrays and numbers) is a validation error.
     *
     * @throws ValidationException
     */
    public static function color(mixed $hex, string $field = 'hex_color'): ?string
    {
        if ($hex === null || (is_string($hex) && trim($hex) === '')) {
            return null;
        }

        return HexColor::normalize($hex) ?? throw ValidationException::withMessages([$field => __('admin.attributes.options.hex_invalid')]);
    }
}
