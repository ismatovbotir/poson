<?php

namespace App\Actions\Catalog;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\CategoryAttribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a library attribute and its options.
 *
 * - The type is locked once products have values for the attribute.
 * - Text attributes are never filters (also on existing category assignments).
 * - Options (select / multiselect only) follow the given list: rows with an
 *   id are updated, new rows go through CreateAttributeOption (dedupe on uz),
 *   options missing from the list go through DeleteAttributeOption. Other
 *   types have no options.
 */
class SaveAttribute
{
    public function __construct(
        private CreateAttributeOption $createOption,
        private DeleteAttributeOption $deleteOption,
    ) {}

    /**
     * @param  array{code: string, name: array<string, string|null>, unit: string|null, type: AttributeType, is_filterable: bool, sort: int|null}  $data
     * @param  list<array{id: int|null, value: array<string, string|null>}>  $options
     *
     * @throws ValidationException
     */
    public function handle(?Attribute $attribute, array $data, array $options = []): Attribute
    {
        $attribute ??= new Attribute;
        $type = $data['type'];

        if (trim((string) ($data['name']['uz'] ?? '')) === '') {
            throw ValidationException::withMessages(['name.uz' => __('validation.required', ['attribute' => __('admin.catalog.name').' (UZ)'])]);
        }

        if ($attribute->exists && $attribute->type !== $type && $attribute->hasValues()) {
            throw ValidationException::withMessages(['type' => __('admin.attributes.errors.type_locked')]);
        }

        return DB::transaction(function () use ($attribute, $data, $type, $options): Attribute {
            $attribute->storeTranslations('name', $data['name']);
            $attribute->fill([
                'code' => mb_strtolower(trim($data['code'])),
                'unit' => filled($data['unit']) ? trim((string) $data['unit']) : null,
                'type' => $type,
                'is_filterable' => $type->canBeFilterable() && $data['is_filterable'],
                'sort' => $data['sort'] ?? (int) Attribute::query()->max('sort') + 10,
            ])->save();

            if (! $type->canBeFilterable()) {
                CategoryAttribute::query()->where('attribute_id', $attribute->id)->update(['is_filterable' => false]);
            }

            $this->syncOptions($attribute, $type->hasOptions() ? $options : []);

            return $attribute;
        });
    }

    /**
     * Existing rows (with an id) are updated first; new rows then go through
     * CreateAttributeOption, deduplicated only against options that are not
     * in the submitted list (so a renamed option never swallows a new row,
     * and a re-added removed value keeps its id). The rest is deleted.
     *
     * @param  list<array{id: int|null, value: array<string, string|null>}>  $rows
     *
     * @throws ValidationException
     */
    private function syncOptions(Attribute $attribute, array $rows): void
    {
        $existing = $attribute->options()->get()->keyBy('id');
        $rows = array_values($rows);
        $submittedIds = [];

        foreach ($rows as $index => $row) {
            $option = isset($row['id']) ? $existing->get((int) $row['id']) : null;

            if ($option instanceof AttributeOption) {
                $option->storeTranslations('value', $row['value']);
                $option->sort = $index + 1;
                $option->save();
                $submittedIds[] = $option->id;
            }
        }

        $kept = $submittedIds;

        foreach ($rows as $index => $row) {
            if (isset($row['id']) && in_array((int) $row['id'], $submittedIds, true)) {
                continue;
            }

            $option = $this->createOption->handle($attribute, $row['value'], $index + 1, "options.{$index}.value.uz", excludeIds: $kept);

            if ($option->wasRecentlyCreated === false) {
                // An existing option not in the list (removed in the form, re-added as new): reuse it.
                $option->storeTranslations('value', $row['value']);
                $option->sort = $index + 1;
                $option->save();
            }

            $kept[] = $option->id;
        }

        foreach ($existing as $id => $option) {
            if (! in_array($id, $kept, true)) {
                $this->deleteOption->handle($option);
            }
        }
    }
}
