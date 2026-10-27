<?php

namespace App\Livewire\Forms\Admin\RollTypes;

use App\Actions\Catalog\SaveRollType;
use App\Models\RollType;
use Closure;
use Livewire\Form;

/**
 * State and validation of the roll type form (plan 08). Saved through
 * SaveRollType, which also checks the labels / core pair is unique.
 */
class RollTypeForm extends Form
{
    public string $labels_per_roll = '';

    /**
     * Core diameter in mm as typed ("40", "12.7" or "12,7").
     */
    public string $core_diameter_mm = '';

    public bool $is_active = true;

    public string $sort = '';

    public function setRollType(RollType $rollType): void
    {
        $this->labels_per_roll = (string) $rollType->labels_per_roll;
        $this->core_diameter_mm = rtrim(rtrim((string) $rollType->core_diameter_mm, '0'), '.');
        $this->is_active = $rollType->is_active;
        $this->sort = (string) $rollType->sort;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'labels_per_roll' => ['required', 'integer', 'min:'.RollType::MIN_LABELS, 'max:'.RollType::MAX_LABELS],
            'core_diameter_mm' => ['required', 'string', 'max:8', function (string $attribute, mixed $value, Closure $fail): void {
                if (SaveRollType::normalizeCore((string) $value) === null) {
                    $fail(__('admin.roll_types.errors.core_invalid'));
                }
            }],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'labels_per_roll' => __('admin.roll_types.fields.labels_per_roll'),
            'core_diameter_mm' => __('admin.roll_types.fields.core_diameter_mm'),
            'sort' => __('admin.catalog.sort'),
        ];
    }

    /**
     * Input for SaveRollType.
     *
     * @return array{labels_per_roll: int, core_diameter_mm: string, is_active: bool, sort: int|null}
     */
    public function rollTypeData(): array
    {
        return [
            'labels_per_roll' => (int) $this->labels_per_roll,
            'core_diameter_mm' => $this->core_diameter_mm,
            'is_active' => $this->is_active,
            'sort' => filled($this->sort) ? (int) $this->sort : null,
        ];
    }
}
