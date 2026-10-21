<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Support\CompanySettings;

/**
 * Hero tab: exactly three stats on the home page, value + label per locale.
 * Brand rule: service facts only, never price or discount claims.
 */
class HeroForm extends SettingsTabForm
{
    public const COUNT = 3;

    /** @var list<array{value: array<string, string>, label: array<string, string>}> */
    public array $stats = [];

    public function fillFrom(CompanySettings $settings): void
    {
        $stored = array_values((array) $settings->get('hero_stats', []));
        $this->stats = [];

        for ($index = 0; $index < self::COUNT; $index++) {
            $stat = is_array($stored[$index] ?? null) ? $stored[$index] : [];

            $this->stats[] = [
                'value' => static::translation($stat['value'] ?? null),
                'label' => static::translation($stat['label'] ?? null),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['stats' => ['required', 'array', 'size:'.self::COUNT]];

        for ($index = 0; $index < self::COUNT; $index++) {
            $rules = [
                ...$rules,
                ...static::translationRules("stats.{$index}.value", 40),
                ...static::translationRules("stats.{$index}.label", 80),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        for ($index = 0; $index < self::COUNT; $index++) {
            $number = $index + 1;

            $attributes = [
                ...$attributes,
                ...static::translationAttributes("stats.{$index}.value", __('admin.settings.company.fields.stat_value', ['number' => $number])),
                ...static::translationAttributes("stats.{$index}.label", __('admin.settings.company.fields.stat_label', ['number' => $number])),
            ];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'hero_stats' => array_map(fn (array $stat): array => [
                'value' => static::trimTranslation($stat['value']),
                'label' => static::trimTranslation($stat['label']),
            ], array_slice(array_values($this->stats), 0, self::COUNT)),
        ];
    }
}
