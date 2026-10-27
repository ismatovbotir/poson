<?php

namespace App\Livewire\Forms\Admin\Banners;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Rules\BannerLink;
use App\Rules\BrandSafeText;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;
use Throwable;

/**
 * State and validation of the banner form (plan 07 7c). The component owns
 * the banner ($this->component->banner); saving goes through SaveBanner
 * (texts, link, dates and both images). starts_at / ends_at are typed in
 * Tashkent time ("Y-m-d\TH:i", datetime-local) and handed over in UTC.
 */
class BannerForm extends Form
{
    public const DATE_FORMAT = 'Y-m-d\TH:i';

    public const DATE_MIN = '2000-01-01';

    public const DATE_MAX = '2100-01-01';

    public string $placement = 'home_hero';

    /**
     * Category ID as the select sends it; empty = none.
     */
    public string $category_id = '';

    /** @var array<string, string> */
    public array $title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $text = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $button_label = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $link = '';

    /**
     * Tashkent time, "Y-m-d\TH:i"; empty = no limit.
     */
    public string $starts_at = '';

    public string $ends_at = '';

    public bool $is_active = true;

    /** @var TemporaryUploadedFile|null */
    public $desktop = null;

    /** @var array<string, string> */
    public array $desktop_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var TemporaryUploadedFile|null */
    public $mobile = null;

    /** @var array<string, string> */
    public array $mobile_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $remove_mobile = false;

    public function setBanner(Banner $banner): void
    {
        $banner->loadMissing('media');

        $this->placement = $banner->placement->value;
        $this->category_id = (string) ($banner->category_id ?? '');
        $this->title = $this->locales($banner->getTranslations('title'));
        $this->text = $this->locales($banner->getTranslations('text'));
        $this->button_label = $this->locales($banner->getTranslations('button_label'));
        $this->link = (string) $banner->link;
        $this->starts_at = $banner->localStartsAt()?->format(self::DATE_FORMAT) ?? '';
        $this->ends_at = $banner->localEndsAt()?->format(self::DATE_FORMAT) ?? '';
        $this->is_active = $banner->is_active;
        $this->desktop = null;
        $this->desktop_alt = $banner->imageAlts(Banner::DESKTOP);
        $this->mobile = null;
        $this->mobile_alt = $banner->imageAlts(Banner::MOBILE);
        $this->remove_mobile = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $hasDesktop = $this->component->banner?->bannerMedia(Banner::DESKTOP) !== null;
        $image = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return [
            'placement' => ['required', Rule::enum(BannerPlacement::class)],
            'category_id' => ['nullable', 'required_if:placement,'.BannerPlacement::Category->value, 'integer', Rule::exists('categories', 'id')],
            'title.uz' => ['required', 'string', 'max:'.Banner::TITLE_MAX, new BrandSafeText],
            'title.ru' => ['nullable', 'string', 'max:'.Banner::TITLE_MAX, new BrandSafeText],
            'title.en' => ['nullable', 'string', 'max:'.Banner::TITLE_MAX, new BrandSafeText],
            'text.*' => ['nullable', 'string', 'max:'.Banner::TEXT_MAX, new BrandSafeText],
            'button_label.*' => ['nullable', 'string', 'max:'.Banner::BUTTON_MAX, new BrandSafeText],
            'link' => ['nullable', 'string', 'max:2048', new BannerLink],
            'starts_at' => ['nullable', 'date_format:'.self::DATE_FORMAT, 'after:'.self::DATE_MIN, 'before:'.self::DATE_MAX],
            'ends_at' => ['nullable', 'date_format:'.self::DATE_FORMAT, 'after:'.self::DATE_MIN, 'before:'.self::DATE_MAX, ...(trim($this->starts_at) !== '' ? ['after:starts_at'] : [])],
            'is_active' => ['boolean'],
            'desktop' => [$hasDesktop ? 'nullable' : 'required', ...$image, 'dimensions:min_width='.Banner::DESKTOP_MEDIUM_WIDTH.',min_height='.Banner::DESKTOP_MEDIUM_HEIGHT],
            'desktop_alt.*' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'mobile' => ['nullable', ...$image, 'dimensions:min_width=640,min_height=480'],
            'mobile_alt.*' => ['nullable', 'string', 'max:255', new BrandSafeText],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'category_id.required_if' => __('admin.banners.errors.category_required'),
            'starts_at.after' => __('admin.banners.errors.date_range'),
            'starts_at.before' => __('admin.banners.errors.date_range'),
            'ends_at.after' => trim($this->starts_at) !== '' && trim($this->ends_at) > '2000' ? __('admin.banners.errors.ends_before_start') : __('admin.banners.errors.date_range'),
            'ends_at.before' => __('admin.banners.errors.date_range'),
            'desktop.required' => __('admin.banners.errors.desktop_required'),
            'desktop.dimensions' => __('admin.banners.errors.desktop_size'),
            'mobile.dimensions' => __('admin.banners.errors.mobile_size'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'placement' => __('admin.banners.fields.placement'),
            'category_id' => __('admin.banners.fields.category'),
            'link' => __('admin.banners.fields.link'),
            'starts_at' => __('admin.banners.fields.starts_at'),
            'ends_at' => __('admin.banners.fields.ends_at'),
            'desktop' => __('admin.banners.fields.desktop'),
            'mobile' => __('admin.banners.fields.mobile'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';

            foreach (['title', 'text', 'button_label'] as $field) {
                $attributes["{$field}.{$locale}"] = __("admin.banners.fields.{$field}").$suffix;
            }

            $attributes["desktop_alt.{$locale}"] = __('admin.image_upload.alt').$suffix;
            $attributes["mobile_alt.{$locale}"] = __('admin.image_upload.alt').$suffix;
        }

        return $attributes;
    }

    /**
     * A typed date (Tashkent) in UTC; the stored one (with its seconds)
     * while the typed minute is unchanged; null when empty or invalid.
     */
    public function dateForSave(string $field): ?CarbonInterface
    {
        $typed = trim($this->{$field});

        if ($typed === '') {
            return null;
        }

        $banner = $this->component->banner;
        $stored = $banner?->{$field};

        if ($stored instanceof CarbonInterface && $stored->copy()->setTimezone(Banner::TIMEZONE)->format(self::DATE_FORMAT) === $typed) {
            return $stored;
        }

        try {
            return Carbon::createFromFormat(self::DATE_FORMAT, $typed, Banner::TIMEZONE)?->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Input for SaveBanner.
     *
     * @return array{placement: string, category_id: int|null, title: array<string, string>, text: array<string, string>, button_label: array<string, string>, link: string, starts_at: CarbonInterface|null, ends_at: CarbonInterface|null, is_active: bool}
     */
    public function bannerData(): array
    {
        return [
            'placement' => $this->placement,
            'category_id' => ctype_digit($this->category_id) ? (int) $this->category_id : null,
            'title' => $this->title,
            'text' => $this->text,
            'button_label' => $this->button_label,
            'link' => $this->link,
            'starts_at' => $this->dateForSave('starts_at'),
            'ends_at' => $this->dateForSave('ends_at'),
            'is_active' => $this->is_active,
        ];
    }

    /**
     * Images for SaveBanner.
     *
     * @return array{desktop: TemporaryUploadedFile|null, desktop_alt: array<string, string>, mobile: TemporaryUploadedFile|null, mobile_alt: array<string, string>, remove_mobile: bool}
     */
    public function imageData(): array
    {
        return [
            'desktop' => $this->desktop,
            'desktop_alt' => $this->desktop_alt,
            'mobile' => $this->mobile,
            'mobile_alt' => $this->mobile_alt,
            'remove_mobile' => $this->remove_mobile,
        ];
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
