<?php

namespace App\Actions\Catalog;

use App\Models\Attribute;
use App\Models\AttributeOption;
use Illuminate\Validation\ValidationException;

/**
 * Add a value to a select / multiselect attribute. Deduplicates on the uz
 * value (trimmed, case-insensitive): an existing option is returned instead
 * of creating a second one. Also used for inline "new value" in the product
 * form (3c), where the caller authorizes attributes.update.
 */
class CreateAttributeOption
{
    /**
     * @param  array<string, string|null>  $value  locale => text, uz required
     * @param  list<int>  $excludeIds  options not considered for deduplication
     *
     * @throws ValidationException
     */
    public function handle(Attribute $attribute, array $value, ?int $sort = null, string $field = 'value', array $excludeIds = []): AttributeOption
    {
        if (! $attribute->type->hasOptions()) {
            throw ValidationException::withMessages([$field => __('admin.attributes.errors.no_options_for_type')]);
        }

        $uz = trim((string) ($value['uz'] ?? ''));

        if ($uz === '') {
            throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => __('admin.attributes.options.value').' (UZ)'])]);
        }

        $existing = $attribute->options()->whereNotIn('id', $excludeIds)->get()->first(
            fn (AttributeOption $option): bool => mb_strtolower(trim((string) $option->getTranslation('value', 'uz', false))) === mb_strtolower($uz),
        );

        if ($existing !== null) {
            return $existing;
        }

        $option = new AttributeOption([
            'attribute_id' => $attribute->id,
            'sort' => $sort ?? (int) $attribute->options()->max('sort') + 1,
        ]);
        $option->storeTranslations('value', $value)->save();

        return $option;
    }
}
