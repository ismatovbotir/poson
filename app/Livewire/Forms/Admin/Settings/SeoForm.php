<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Settings\SettingsFormat;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * SEO tab: search engine verification codes (the content value of the meta
 * tag only, never the tag) plus the logo and default OG image uploads
 * (JPEG/PNG/WebP ≤ 5 MB, no SVG, at most 6000×6000; OG at least 1200×630).
 * Per-bot robots rules come in plan 04c.
 */
class SeoForm extends SettingsTabForm
{
    public string $google = '';

    public string $yandex = '';

    public string $bing = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public bool $remove_logo = false;

    /** @var TemporaryUploadedFile|null */
    public $og_image = null;

    public bool $remove_og_image = false;

    public function fillFrom(CompanySettings $settings): void
    {
        $codes = (array) $settings->get('verification', []);

        foreach (array_keys(SettingsFormat::verificationPatterns()) as $engine) {
            $this->{$engine} = is_string($codes[$engine] ?? null) ? $codes[$engine] : '';
        }

        $this->logo = null;
        $this->remove_logo = false;
        $this->og_image = null;
        $this->remove_og_image = false;
    }

    /**
     * Trim the codes. Only the content value is accepted: a pasted full
     * meta tag fails the format check (the field hint explains this).
     */
    public function normalize(): void
    {
        foreach (array_keys(SettingsFormat::verificationPatterns()) as $engine) {
            $this->{$engine} = trim($this->{$engine});
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=112,min_height=112,max_width=6000,max_height=6000'],
            'og_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=1200,min_height=630,max_width=6000,max_height=6000'],
        ];

        foreach (SettingsFormat::verificationPatterns() as $engine => $pattern) {
            $rules[$engine] = ['nullable', 'string', 'max:100', SettingsFormat::rule($pattern)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'google.regex' => __('admin.settings.company.errors.verification'),
            'yandex.regex' => __('admin.settings.company.errors.verification'),
            'bing.regex' => __('admin.settings.company.errors.verification'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'google' => __('admin.settings.company.fields.google'),
            'yandex' => __('admin.settings.company.fields.yandex'),
            'bing' => __('admin.settings.company.fields.bing'),
            'logo' => __('admin.settings.company.fields.logo'),
            'og_image' => __('admin.settings.company.fields.og_image'),
        ];
    }

    /**
     * Input for UpdateCompanyMedia: media key => new file / remove flag.
     *
     * @return array<string, array{file: TemporaryUploadedFile|null, remove: bool}>
     */
    public function mediaChanges(): array
    {
        return [
            Setting::LOGO => ['file' => $this->logo, 'remove' => $this->remove_logo],
            Setting::OG_IMAGE => ['file' => $this->og_image, 'remove' => $this->remove_og_image],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'verification' => [
                'google' => $this->google,
                'yandex' => $this->yandex,
                'bing' => $this->bing,
            ],
        ];
    }
}
