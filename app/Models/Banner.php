<?php

namespace App\Models;

use App\Enums\BannerPlacement;
use App\Enums\BannerState;
use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Site\BannerDirectory;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

/**
 * Banner (plan 07 7c): a slide of the home slider below the static hero
 * (placement home_hero) or the banner on page 1 of one category listing
 * (placement category). Title, text and button label per locale (uz
 * required; ru and en fall back to uz); a link that is an internal path
 * stored without a locale prefix or an https URL (App\Support\Site\BannerLink).
 *
 * Images (medialibrary, like HasCatalogImage: private original, WebP
 * conversions, queued; alt per locale in the custom property "alt"):
 * "desktop" (required) is cropped to DESKTOP_WIDTH × DESKTOP_HEIGHT, the
 * optional "mobile" to MOBILE_WIDTH × MOBILE_HEIGHT, so the site can set
 * exact width / height (no layout shift). No text is baked into the images
 * (K7): the texts are rendered over them.
 *
 * Shown while is_active and now is within starts_at / ends_at (UTC; typed
 * in Tashkent time in admin). Written only through App\Actions\Banners\*.
 * Saving or deleting flushes the BannerDirectory cache (also after the
 * commit, once the images are stored).
 *
 * @property int $id
 * @property BannerPlacement $placement
 * @property int|null $category_id
 * @property string|null $link
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['placement', 'category_id', 'title', 'text', 'button_label', 'link', 'starts_at', 'ends_at', 'is_active', 'sort'])]
class Banner extends Model implements HasMedia
{
    /** @use HasFactory<BannerFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * Time zone of the admin date inputs.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    /**
     * Size of the desktop slide (3.2 : 1) and of its smaller variant.
     */
    public const DESKTOP_WIDTH = 1920;

    public const DESKTOP_HEIGHT = 600;

    public const DESKTOP_MEDIUM_WIDTH = 1200;

    public const DESKTOP_MEDIUM_HEIGHT = 375;

    /**
     * Size of the mobile slide (4 : 3).
     */
    public const MOBILE_WIDTH = 960;

    public const MOBILE_HEIGHT = 720;

    /**
     * Longest texts per locale (characters).
     */
    public const TITLE_MAX = 90;

    public const TEXT_MAX = 200;

    public const BUTTON_MAX = 30;

    /**
     * @var list<string>
     */
    public array $translatable = ['title', 'text', 'button_label'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    protected static function booted(): void
    {
        $flush = function (): void {
            BannerDirectory::flush();
            DB::afterCommit(fn () => BannerDirectory::flush());
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => BannerPlacement::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * The desktop image is the record's main image (UpdateCatalogImage default).
     */
    public function imageCollection(): string
    {
        return self::DESKTOP;
    }

    public function registerMediaCollections(): void
    {
        foreach ([self::DESKTOP, self::MOBILE] as $collection) {
            $this->addMediaCollection($collection)
                ->singleFile()
                ->acceptsMimeTypes(self::IMAGE_MIMES)
                ->useDisk(config('media-library.originals_disk', 'local'))
                ->storeConversionsOnDisk(config('media-library.disk_name', 'public'));
        }
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 480, 150)
            ->format('webp')
            ->performOnCollections(self::DESKTOP)
            ->queued();

        $this->addMediaConversion('medium')
            ->fit(Fit::Crop, self::DESKTOP_MEDIUM_WIDTH, self::DESKTOP_MEDIUM_HEIGHT)
            ->format('webp')
            ->performOnCollections(self::DESKTOP)
            ->queued();

        $this->addMediaConversion('large')
            ->fit(Fit::Crop, self::DESKTOP_WIDTH, self::DESKTOP_HEIGHT)
            ->format('webp')
            ->performOnCollections(self::DESKTOP)
            ->queued();

        $this->addMediaConversion('mobile')
            ->fit(Fit::Crop, self::MOBILE_WIDTH, self::MOBILE_HEIGHT)
            ->format('webp')
            ->performOnCollections(self::MOBILE)
            ->queued();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The image of a collection (desktop or mobile).
     */
    public function bannerMedia(string $collection = self::DESKTOP): ?Media
    {
        return $this->getFirstMedia($collection);
    }

    /**
     * URL of a conversion of the desktop image ("thumb", "medium", "large")
     * or of the mobile one ("mobile"); null without the image or while the
     * queued conversion hasn't run yet (the original is never public).
     */
    public function imageUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->bannerMedia($conversion === 'mobile' ? self::MOBILE : self::DESKTOP);

        return $media !== null && $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : null;
    }

    /**
     * Alt text per locale of the desktop or mobile image: {uz, ru, en}.
     *
     * @return array<string, string>
     */
    public function imageAlts(string $collection = self::DESKTOP): array
    {
        $alt = (array) ($this->bannerMedia($collection)?->getCustomProperty('alt') ?? []);

        return collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => (string) ($alt[$locale] ?? '')])
            ->all();
    }

    /**
     * An image of the collection is saved but its conversions are still queued.
     */
    public function bannerImageProcessing(string $collection = self::DESKTOP): bool
    {
        return $this->bannerMedia($collection) !== null && $this->imageUrl($collection === self::MOBILE ? 'mobile' : 'thumb') === null;
    }

    /**
     * Banners of a placement.
     *
     * @param  Builder<Banner>  $query
     */
    public function scopePlacedAt(Builder $query, BannerPlacement $placement): void
    {
        $query->where('banners.placement', $placement->value);
    }

    /**
     * Active and within their date window right now.
     *
     * @param  Builder<Banner>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('banners.is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('banners.starts_at')->orWhere('banners.starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('banners.ends_at')->orWhere('banners.ends_at', '>', now()));
    }

    /**
     * Site order: sort, then the oldest first.
     *
     * @param  Builder<Banner>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('banners.sort')->orderBy('banners.id');
    }

    /**
     * Admin state: inactive, expired, scheduled or active (shown now).
     */
    public function state(): BannerState
    {
        return match (true) {
            ! $this->is_active => BannerState::Inactive,
            $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo(now()) => BannerState::Expired,
            $this->starts_at !== null && $this->starts_at->greaterThan(now()) => BannerState::Scheduled,
            default => BannerState::Active,
        };
    }

    public function isLive(): bool
    {
        return $this->state() === BannerState::Active;
    }

    public function localStartsAt(): ?Carbon
    {
        return $this->starts_at?->copy()->setTimezone(self::TIMEZONE);
    }

    public function localEndsAt(): ?Carbon
    {
        return $this->ends_at?->copy()->setTimezone(self::TIMEZONE);
    }
}
