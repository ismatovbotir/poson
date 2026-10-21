<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Support\CompanySettings;
use App\Support\Settings\SettingsFormat;
use Illuminate\Validation\Rule;

/**
 * Tracking tab (plan 04 S8): GA4 measurement ID and Yandex Metrika counter
 * ID only, regex-validated; no code snippets. Enabling needs at least one
 * ID. Needs settings.integrations as well (plan 04 §6).
 */
class TrackingForm extends SettingsTabForm
{
    public bool $enabled = false;

    public string $ga4 = '';

    public string $metrika = '';

    public function fillFrom(CompanySettings $settings): void
    {
        $tracking = (array) $settings->get('tracking', []);

        $this->enabled = ($tracking['enabled'] ?? false) === true;
        $this->ga4 = is_scalar($tracking['ga4'] ?? null) ? (string) $tracking['ga4'] : '';
        $this->metrika = is_scalar($tracking['metrika'] ?? null) ? (string) $tracking['metrika'] : '';
    }

    public function normalize(): void
    {
        $this->ga4 = strtoupper(trim($this->ga4));
        $this->metrika = trim($this->metrika);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'ga4' => [Rule::requiredIf(fn (): bool => $this->enabled && $this->metrika === ''), 'nullable', 'string', 'max:20', SettingsFormat::rule(SettingsFormat::GA4)],
            'metrika' => ['nullable', 'string', 'max:12', SettingsFormat::rule(SettingsFormat::METRIKA)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'ga4.required' => __('admin.settings.company.errors.tracking_id_required'),
            'ga4.regex' => __('admin.settings.company.errors.ga4'),
            'metrika.regex' => __('admin.settings.company.errors.metrika'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'enabled' => __('admin.settings.company.fields.tracking_enabled'),
            'ga4' => __('admin.settings.company.fields.ga4'),
            'metrika' => __('admin.settings.company.fields.metrika'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'tracking' => [
                'enabled' => $this->enabled,
                'ga4' => $this->ga4,
                'metrika' => $this->metrika,
            ],
        ];
    }
}
