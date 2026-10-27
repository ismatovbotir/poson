<?php

namespace App\Actions\Banners;

use App\Actions\Catalog\UpdateCatalogImage;
use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Models\Category;
use App\Rules\BrandSafeText;
use App\Support\Site\BannerLink;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a banner with its desktop and mobile images (plan 07 7c),
 * in one transaction: a rejected image rolls the whole save back.
 *
 * Re-checks what must never be stored whatever the caller: the uz title,
 * the text lengths, the brand rule (BrandSafeText) on every public text and
 * alt, a category for the category placement (none for the home slider), a
 * link that is an internal path or an https URL (BannerLink), dates between
 * 2000 and 2099 with the end after the start, and a desktop image.
 *
 * A new banner, or one moved to another placement / category, goes to the
 * end of its list (sort); the order is changed with MoveBanner.
 */
class SaveBanner
{
    public const DATE_MIN = '2000-01-01';

    public const DATE_MAX = '2100-01-01';

    /**
     * Text fields => longest value per locale.
     */
    private const TEXTS = ['title' => Banner::TITLE_MAX, 'text' => Banner::TEXT_MAX, 'button_label' => Banner::BUTTON_MAX];

    public function __construct(private UpdateCatalogImage $updateImage) {}

    /**
     * @param  array{
     *     placement: string,
     *     category_id: int|null,
     *     title: array<string, string|null>,
     *     text: array<string, string|null>,
     *     button_label: array<string, string|null>,
     *     link: string|null,
     *     starts_at: CarbonInterface|null,
     *     ends_at: CarbonInterface|null,
     *     is_active: bool
     * }  $data
     * @param  array{
     *     desktop?: UploadedFile|null,
     *     desktop_alt?: array<string, string|null>,
     *     mobile?: UploadedFile|null,
     *     mobile_alt?: array<string, string|null>,
     *     remove_mobile?: bool
     * }  $images
     *
     * @throws ValidationException
     */
    public function handle(?Banner $banner, array $data, array $images = []): Banner
    {
        $banner ??= new Banner;
        $placement = BannerPlacement::tryFrom((string) ($data['placement'] ?? ''));
        $categoryId = $placement === BannerPlacement::Category ? (int) ($data['category_id'] ?? 0) : null;
        $link = BannerLink::normalize($data['link'] ?? null);

        $this->check($data, $placement, $categoryId, $link);

        return DB::transaction(function () use ($banner, $data, $images, $placement, $categoryId, $link): Banner {
            $banner->storeTranslations('title', $data['title']);
            $banner->storeTranslations('text', $data['text'] ?? []);
            $banner->storeTranslations('button_label', $data['button_label'] ?? []);

            $moved = ! $banner->exists || $banner->placement !== $placement || $banner->category_id !== $categoryId;

            $banner->fill([
                'placement' => $placement,
                'category_id' => $categoryId,
                'link' => $link,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            if ($moved) {
                $banner->sort = $this->nextSort($placement, $categoryId);
            }

            $banner->save();

            $this->updateImage->handle($banner, $images['desktop'] ?? null, $images['desktop_alt'] ?? [], field: 'desktop', collection: Banner::DESKTOP);
            $this->updateImage->handle($banner, $images['mobile'] ?? null, $images['mobile_alt'] ?? [], (bool) ($images['remove_mobile'] ?? false), 'mobile', Banner::MOBILE);

            if (! $banner->media()->where('collection_name', Banner::DESKTOP)->exists()) {
                throw ValidationException::withMessages(['desktop' => __('admin.banners.errors.desktop_required')]);
            }

            return $banner->unsetRelation('media');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function check(array $data, ?BannerPlacement $placement, ?int $categoryId, ?string $link): void
    {
        $errors = [];

        if ($placement === null) {
            $errors['placement'] = __('validation.required', ['attribute' => __('admin.banners.fields.placement')]);
        }

        if ($placement === BannerPlacement::Category && ($categoryId < 1 || ! Category::query()->whereKey($categoryId)->exists())) {
            $errors['category_id'] = __('admin.banners.errors.category_required');
        }

        if (trim((string) ($data['title']['uz'] ?? '')) === '') {
            $errors['title.uz'] = __('validation.required', ['attribute' => __('admin.banners.fields.title').' (UZ)']);
        }

        foreach (self::TEXTS as $field => $max) {
            foreach (config('app.supported_locales') as $locale) {
                $text = trim((string) ($data[$field][$locale] ?? ''));
                $attribute = __("admin.banners.fields.{$field}").' ('.strtoupper($locale).')';

                if (mb_strlen($text) > $max) {
                    $errors["{$field}.{$locale}"] = __('validation.max.string', ['attribute' => $attribute, 'max' => $max]);
                } elseif ($text !== '' && BrandSafeText::violates($text)) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => $attribute]);
                }
            }
        }

        if ($link !== null && ! BannerLink::isValid($link)) {
            $errors['link'] = __('admin.banners.errors.link');
        }

        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        foreach (['starts_at' => $startsAt, 'ends_at' => $endsAt] as $key => $date) {
            if ($date instanceof CarbonInterface && ($date->lessThanOrEqualTo(Carbon::parse(self::DATE_MIN)) || $date->greaterThanOrEqualTo(Carbon::parse(self::DATE_MAX)))) {
                $errors[$key] = __('admin.banners.errors.date_range');
            }
        }

        if ($startsAt instanceof CarbonInterface && $endsAt instanceof CarbonInterface && ! isset($errors['ends_at']) && $endsAt->lessThanOrEqualTo($startsAt)) {
            $errors['ends_at'] = __('admin.banners.errors.ends_before_start');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nextSort(BannerPlacement $placement, ?int $categoryId): int
    {
        return (int) Banner::query()
            ->placedAt($placement)
            ->where('category_id', $categoryId)
            ->max('sort') + 1;
    }
}
