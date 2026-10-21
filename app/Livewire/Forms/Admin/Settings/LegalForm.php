<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Rules\BrandSafeText;
use App\Support\CompanySettings;
use App\Support\Settings\SettingsFormat;

/**
 * Legal tab: requisites for invoices, the public offer and JSON-LD
 * (legalName, taxID). One bank account, optional VAT code (plan 04 §11.8).
 * Needs settings.integrations as well (plan 04 §6).
 */
class LegalForm extends SettingsTabForm
{
    /**
     * Numeric fields => pattern.
     */
    public const NUMBERS = [
        'inn' => SettingsFormat::INN,
        'vat_code' => SettingsFormat::VAT,
        'mfo' => SettingsFormat::MFO,
        'account' => SettingsFormat::ACCOUNT,
        'oked' => SettingsFormat::OKED,
    ];

    /** @var array<string, string> */
    public array $legal_name = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $inn = '';

    public string $vat_code = '';

    public string $bank = '';

    public string $mfo = '';

    public string $account = '';

    public string $oked = '';

    public string $director = '';

    /** @var array<string, string> */
    public array $legal_address = ['uz' => '', 'ru' => '', 'en' => ''];

    public function fillFrom(CompanySettings $settings): void
    {
        $legal = (array) $settings->get('legal', []);
        $string = fn (string $key): string => is_scalar($legal[$key] ?? null) ? (string) $legal[$key] : '';

        $this->legal_name = static::translation($legal['legal_name'] ?? null);
        $this->inn = $string('inn');
        $this->vat_code = $string('vat_code');
        $this->bank = $string('bank');
        $this->mfo = $string('mfo');
        $this->account = $string('account');
        $this->oked = $string('oked');
        $this->director = $string('director');
        $this->legal_address = static::translation($legal['legal_address'] ?? null);
    }

    /**
     * Numbers are often pasted with spaces or dashes: keep digits only when
     * that's all there is besides separators.
     */
    public function normalize(): void
    {
        foreach (array_keys(self::NUMBERS) as $field) {
            $value = trim($this->{$field});

            $this->{$field} = preg_match('/^[\d\s.\-]+$/', $value) === 1 ? (string) preg_replace('/\D+/', '', $value) : $value;
        }

        $this->bank = trim($this->bank);
        $this->director = trim($this->director);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            ...static::translationRules('legal_name', 255, required: false),
            'bank' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'director' => ['nullable', 'string', 'max:255', new BrandSafeText],
            ...static::translationRules('legal_address', 255, required: false),
        ];

        foreach (self::NUMBERS as $field => $pattern) {
            $rules[$field] = ['nullable', 'string', SettingsFormat::rule($pattern)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $messages = [];

        foreach (array_keys(self::NUMBERS) as $field) {
            $messages["{$field}.regex"] = __("admin.settings.company.errors.{$field}");
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            ...static::translationAttributes('legal_name', __('admin.settings.company.fields.legal_name')),
            ...static::translationAttributes('legal_address', __('admin.settings.company.fields.legal_address')),
            'bank' => __('admin.settings.company.fields.bank'),
            'director' => __('admin.settings.company.fields.director'),
        ];

        foreach (array_keys(self::NUMBERS) as $field) {
            $attributes[$field] = __("admin.settings.company.fields.{$field}");
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'legal' => [
                'legal_name' => static::trimTranslation($this->legal_name),
                'inn' => $this->inn,
                'vat_code' => $this->vat_code,
                'bank' => $this->bank,
                'mfo' => $this->mfo,
                'account' => $this->account,
                'oked' => $this->oked,
                'director' => $this->director,
                'legal_address' => static::trimTranslation($this->legal_address),
            ],
        ];
    }
}
