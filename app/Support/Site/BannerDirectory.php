<?php

namespace App\Support\Site;

use App\Data\Site\BannerSlide;
use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Rules\BrandSafeText;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The banners the site may show (plan 07 7c), cached: the home slider
 * (placement home_hero) and the banner of each category listing.
 *
 * The cache holds every active banner that hasn't ended and whose desktop
 * WebP conversion exists (texts and alts in all locales, the image URLs),
 * in sort order. It expires at the next starts_at / ends_at still in the
 * future (at most a day), and the date window is checked again on every
 * read, so a scheduled banner appears and an expired one disappears without
 * a cron. Banner saves and deletes, MoveBanner and finished banner image
 * conversions flush it.
 *
 * Read time (K7): a banner whose title, text, button label or alt in the
 * current locale breaks the brand rule (BrandSafeText, e.g. edited directly
 * in the DB) is dropped; an invalid link is dropped from the banner. The
 * cache is locale-neutral: an internal link is stored without a locale and
 * gets the current one here (BannerLink::href()).
 *
 * Two queries (banners, then their media) on a cache miss, none on a hit;
 * a per-request copy in the container.
 *
 * @phpstan-type Row array{id: int, placement: string, category_id: int|null, title: array<string, string>, text: array<string, string>, button_label: array<string, string>, link: string|null, starts_at: string|null, ends_at: string|null, large: string, medium: string|null, mobile: string|null, alt: array<string, string>, mobile_alt: array<string, string>}
 */
class BannerDirectory
{
    public const CACHE_KEY = 'site.banners.v1';

    /**
     * Longest the cache lives without a schedule boundary (seconds).
     */
    public const MAX_TTL = 86400;

    /**
     * Most banners kept (home slider + all category banners).
     */
    public const LIMIT = 200;

    private const MEMO = 'site.banners.memo';

    /**
     * Slides of the home slider in the current locale, in sort order.
     *
     * @return list<BannerSlide>
     */
    public static function home(): array
    {
        $slides = [];

        foreach (static::rows() as $row) {
            if ($row['placement'] === BannerPlacement::HomeHero->value && static::isLive($row) && ($slide = static::slide($row)) !== null) {
                $slides[] = $slide;
            }
        }

        return $slides;
    }

    /**
     * The first live banner (by sort) of the category, in the current locale.
     */
    public static function forCategory(int $categoryId): ?BannerSlide
    {
        foreach (static::rows() as $row) {
            if ($row['placement'] === BannerPlacement::Category->value && $row['category_id'] === $categoryId && static::isLive($row) && ($slide = static::slide($row)) !== null) {
                return $slide;
            }
        }

        return null;
    }

    /**
     * @return list<Row>
     */
    protected static function rows(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $rows = Cache::get(self::CACHE_KEY);

        if (! is_array($rows)) {
            $rows = static::build();
            Cache::put(self::CACHE_KEY, $rows, static::expiry($rows));
        }

        app()->instance(self::MEMO, $rows);

        return $rows;
    }

    /**
     * @return list<Row>
     */
    protected static function build(): array
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('media')
            ->orderBy('placement')
            ->ordered()
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($banners as $banner) {
            $large = $banner->imageUrl('large');

            if ($large === null) {
                continue;
            }

            $rows[] = [
                'id' => $banner->id,
                'placement' => $banner->placement->value,
                'category_id' => $banner->category_id,
                'title' => $banner->getTranslations('title'),
                'text' => $banner->getTranslations('text'),
                'button_label' => $banner->getTranslations('button_label'),
                'link' => $banner->link,
                'starts_at' => $banner->starts_at?->toAtomString(),
                'ends_at' => $banner->ends_at?->toAtomString(),
                'large' => $large,
                'medium' => $banner->imageUrl('medium'),
                'mobile' => $banner->imageUrl('mobile'),
                'alt' => $banner->imageAlts(Banner::DESKTOP),
                'mobile_alt' => $banner->imageAlts(Banner::MOBILE),
            ];
        }

        return $rows;
    }

    /**
     * The next starts_at / ends_at still in the future, at most MAX_TTL away.
     *
     * @param  list<Row>  $rows
     */
    public static function expiry(array $rows): CarbonInterface
    {
        $until = now()->addSeconds(self::MAX_TTL);

        foreach ($rows as $row) {
            foreach ([$row['starts_at'], $row['ends_at']] as $date) {
                if ($date === null) {
                    continue;
                }

                $at = Carbon::parse($date);

                if ($at->greaterThan(now()) && $at->lessThan($until)) {
                    $until = $at;
                }
            }
        }

        return $until;
    }

    /**
     * @param  Row  $row
     */
    protected static function isLive(array $row): bool
    {
        return ($row['starts_at'] === null || Carbon::parse($row['starts_at'])->lessThanOrEqualTo(now()))
            && ($row['ends_at'] === null || Carbon::parse($row['ends_at'])->greaterThan(now()));
    }

    /**
     * The banner in the current locale; null when a text breaks the brand rule.
     *
     * @param  Row  $row
     */
    protected static function slide(array $row): ?BannerSlide
    {
        $locale = app()->getLocale();
        $title = static::translated($row['title'], $locale);
        $text = static::translated($row['text'], $locale);
        $button = static::translated($row['button_label'], $locale);
        $alt = trim((string) ($row['alt'][$locale] ?? ''));
        $mobileAlt = trim((string) ($row['mobile_alt'][$locale] ?? ''));

        if ($title === '') {
            return null;
        }

        foreach ([$title, $text, $button, $alt, $mobileAlt] as $value) {
            if ($value !== '' && BrandSafeText::violates($value)) {
                return null;
            }
        }

        // Internal paths are stored without a locale (decision 2026-10-27): the href gets the current one.
        $href = BannerLink::href($row['link'], $locale);

        return new BannerSlide(
            id: $row['id'],
            title: $title,
            text: $text,
            buttonLabel: $href !== null ? ($button !== '' ? $button : __('site.banners.more')) : '',
            href: $href,
            external: $href !== null && BannerLink::isExternal($row['link']),
            image: $row['large'],
            imageMedium: $row['medium'],
            imageAlt: $alt,
            mobileImage: $row['mobile'],
            mobileImageAlt: $mobileAlt !== '' ? $mobileAlt : $alt,
        );
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function translated(array $values, string $locale): string
    {
        $value = trim((string) ($values[$locale] ?? ''));

        return $value !== '' ? $value : trim((string) ($values[config('app.default_locale', 'uz')] ?? ''));
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
        Cache::forget(self::CACHE_KEY);
    }
}
