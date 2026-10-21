<?php

namespace App\Livewire\Forms\Admin\Attributes;

use App\Enums\AttributeType;
use App\Models\Attribute;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the attribute form, including the options
 * repeater for select / multiselect. Saved through SaveAttribute.
 */
class AttributeForm extends Form
{
    public string $code = '';

    /** @var array<string, string> */
    public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $unit = '';

    public string $type = 'select';

    public bool $is_filterable = true;

    public string $sort = '';

    /**
     * Option rows in order; id null for new rows.
     *
     * @var list<array{id: int|null, value: array<string, string>}>
     */
    public array $options = [];

    public function setAttributeModel(Attribute $attribute): void
    {
        $attribute->loadMissing('options');

        $this->code = $attribute->code;
        $this->name = $this->locales($attribute->getTranslations('name'));
        $this->unit = (string) $attribute->unit;
        $this->type = $attribute->type->value;
        $this->is_filterable = $attribute->is_filterable;
        $this->sort = (string) $attribute->sort;
        $this->options = $attribute->options
            ->map(fn ($option): array => ['id' => $option->id, 'value' => $this->locales($option->getTranslations('value'))])
            ->values()
            ->all();
    }

    public function typeEnum(): AttributeType
    {
        return AttributeType::tryFrom($this->type) ?? AttributeType::Text;
    }

    public function addOption(): void
    {
        $this->options[] = ['id' => null, 'value' => ['uz' => '', 'ru' => '', 'en' => '']];
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function moveOption(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->options[$index], $this->options[$target])) {
            return;
        }

        [$this->options[$index], $this->options[$target]] = [$this->options[$target], $this->options[$index]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $attributeId = $this->component->attribute?->id;
        $hasOptions = $this->typeEnum()->hasOptions();

        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('attributes', 'code')->ignore($attributeId)],
            'name.uz' => ['required', 'string', 'max:255'],
            'name.ru' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:16'],
            'type' => ['required', Rule::enum(AttributeType::class)],
            'is_filterable' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'options' => [$hasOptions ? 'required' : 'nullable', 'array', 'max:500'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.value.uz' => $hasOptions ? ['required', 'string', 'max:255', 'distinct:ignore_case'] : ['nullable'],
            'options.*.value.ru' => ['nullable', 'string', 'max:255'],
            'options.*.value.en' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'code.regex' => __('admin.attributes.errors.code_format'),
            'options.required' => __('admin.attributes.errors.options_required'),
            'options.*.value.uz.distinct' => __('admin.attributes.errors.option_duplicate'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'code' => __('admin.attributes.fields.code'),
            'unit' => __('admin.attributes.fields.unit'),
            'type' => __('admin.attributes.fields.type'),
            'sort' => __('admin.catalog.sort'),
            'options' => __('admin.attributes.options.title'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $attributes["name.{$locale}"] = __('admin.catalog.name').$suffix;
            $attributes["options.*.value.{$locale}"] = __('admin.attributes.options.value').$suffix;
        }

        return $attributes;
    }

    /**
     * Input for SaveAttribute.
     *
     * @return array{code: string, name: array<string, string>, unit: string|null, type: AttributeType, is_filterable: bool, sort: int|null}
     */
    public function attributeData(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'unit' => filled($this->unit) ? $this->unit : null,
            'type' => $this->typeEnum(),
            'is_filterable' => $this->is_filterable,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
        ];
    }

    /**
     * Option rows for SaveAttribute.
     *
     * @return list<array{id: int|null, value: array<string, string>}>
     */
    public function optionRows(): array
    {
        return array_values(array_map(fn (array $row): array => [
            'id' => filled($row['id'] ?? null) ? (int) $row['id'] : null,
            'value' => $row['value'],
        ], $this->options));
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
