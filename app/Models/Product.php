<?php

namespace App\Models;

use App\Enums\PriceLevel;
use App\Enums\ProductBadge;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Concerns\StoresTranslations;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\HttpsUrl;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

/**
 * Catalog product (plan 03 §2). Hardware, consumables, software and services.
 *
 * Prices are integer tiyin. "price" is the Retail price: the only one the
 * public site and the public cart ever use; null means "price on request"
 * (sold through a quote). Dealer / Wholesale are admin-only and fall back to
 * Retail (priceFor()).
 *
 * Images: medialibrary collection "images" (several, ordered), originals on
 * the private media-library.originals_disk, WebP conversions "thumb" (400)
 * and "large" (1200) on the public disk, alt {uz, ru, en} in the custom
 * property "alt". Eager-load "media" on lists (strict models).
 *
 * Writes go through App\Actions\Catalog\SaveProduct (search_text and
 * has_missing_specs are maintained there).
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $brand_id
 * @property ProductType $type
 * @property string $sku
 * @property string|null $model
 * @property string $slug
 * @property int|null $price
 * @property int|null $price_dealer
 * @property int|null $price_wholesale
 * @property ProductUnit $unit
 * @property int $min_qty
 * @property SaleMode $sale_mode
 * @property StockStatus $stock_status
 * @property int|null $warranty_months
 * @property ProductBadge|null $badge
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $has_missing_specs
 * @property string|null $search_text
 * @property int $sort
 */
#[Fillable([
    'category_id', 'brand_id', 'type', 'sku', 'model', 'slug',
    'name', 'short_description', 'description', 'meta_title', 'meta_description',
    'price', 'price_dealer', 'price_wholesale', 'unit', 'min_qty', 'sale_mode', 'stock_status',
    'warranty_months', 'badge', 'is_active', 'is_featured', 'has_missing_specs', 'search_text', 'sort',
])]
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasTranslations, InteractsWithMedia, SoftDeletes, StoresTranslations;

    public const IMAGES = 'images';

    /**
     * Accepted image upload types (no SVG: it can carry scripts).
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Most attributes shown on the product card.
     */
    public const MAX_KEY_SPECS = 4;

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'short_description', 'description', 'meta_title', 'meta_description'];

    /**
     * Catalog changes flush the cached /sitemap.xml once the surrounding
     * transaction commits (immediately without one), so a request can't
     * re-cache the old state in between.
     */
    protected static function booted(): void
    {
        $flush = fn () => DB::afterCommit(function (): void {
            Sitemap::flush();
            LlmsTxt::flush();
        });

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
        static::forceDeleted(fn (Product $product) => $product->faqItems()->delete());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'unit' => ProductUnit::class,
            'sale_mode' => SaleMode::class,
            'stock_status' => StockStatus::class,
            'badge' => ProductBadge::class,
            'price' => 'integer',
            'price_dealer' => 'integer',
            'price_wholesale' => 'integer',
            'min_qty' => 'integer',
            'warranty_months' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'has_missing_specs' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES)
            ->acceptsMimeTypes(self::IMAGE_MIMES)
            ->useDisk(config('media-library.originals_disk', 'local'))
            ->storeConversionsOnDisk(config('media-library.disk_name', 'public'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Max, 400, 400)
            ->format('webp')
            ->performOnCollections(self::IMAGES)
            ->queued();

        $this->addMediaConversion('large')
            ->fit(Fit::Max, 1200, 1200)
            ->format('webp')
            ->performOnCollections(self::IMAGES)
            ->queued();
    }

    /**
     * Permanently delete the row but leave the media to the caller
     * (ForceDeleteProduct removes media rows in its transaction and the
     * files after the commit).
     */
    public function forceDeletePreservingMedia(): bool
    {
        $this->deletePreservingMedia = true;

        return (bool) $this->forceDelete();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Spec values in form order (a multiselect has one row per option).
     *
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Key spec values (shown on the product card and above the buy box).
     *
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function keySpecValues(): HasMany
    {
        return $this->attributeValues()->where('is_key', true);
    }

    /**
     * Category attributes this product deliberately does not use.
     *
     * @return HasMany<ProductAttributeSkip, $this>
     */
    public function attributeSkips(): HasMany
    {
        return $this->hasMany(ProductAttributeSkip::class);
    }

    /**
     * @return HasMany<ProductDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * FAQ of the product page (plan 04 T12), in admin order.
     *
     * @return MorphMany<FaqItem, $this>
     */
    public function faqItems(): MorphMany
    {
        return $this->morphMany(FaqItem::class, 'faqable')->orderBy('sort')->orderBy('id');
    }

    /**
     * News posts about this product (plan 07 7b; the product page lists the visible ones).
     *
     * @return BelongsToMany<NewsPost, $this>
     */
    public function newsPosts(): BelongsToMany
    {
        return $this->belongsToMany(NewsPost::class);
    }

    /**
     * Links from this product to related ones (accessories, consumables, ...).
     *
     * @return HasMany<ProductRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(ProductRelation::class)->orderBy('type')->orderBy('sort')->orderBy('id');
    }

    /**
     * Published: active product in an active category.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('products.is_active', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }

    /**
     * On the public site: active, in a category that is active together with
     * all its ancestors (CategoryTree::visibleIds()). Trashed products are
     * excluded by the soft-delete scope.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('products.is_active', true)
            ->whereIn('products.category_id', CategoryTree::visibleIds());
    }

    /**
     * Whether the product page is public (see scopeVisible()).
     */
    public function isVisible(): bool
    {
        return $this->is_active && ! $this->trashed() && CategoryTree::isVisible($this->category_id);
    }

    /**
     * The brand as shown on the site: null while the brand is inactive.
     * Needs "brand" loaded.
     */
    public function publicBrand(): ?Brand
    {
        $brand = $this->getRelationValue('brand');

        return $brand instanceof Brand && $brand->is_active ? $brand : null;
    }

    /**
     * Drafts (not published).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeDraft(Builder $query): void
    {
        $query->where('products.is_active', false);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('products.is_featured', true);
    }

    /**
     * Price of a customer price level in tiyin; an empty level falls back to
     * Retail. Null = price on request.
     */
    public function priceFor(PriceLevel $level): ?int
    {
        return $this->getAttribute($level->column()) ?? $this->price;
    }

    /**
     * No Retail price: the site shows "price on request" and a quote button.
     */
    public function isPriceOnRequest(): bool
    {
        return $this->price === null;
    }

    /**
     * The sale mode the site applies: a product without a price is always quote-only.
     */
    public function effectiveSaleMode(): SaleMode
    {
        return $this->isPriceOnRequest() ? SaleMode::Quote : $this->sale_mode;
    }

    /**
     * Whether the site shows a stock status (badge, JSON-LD availability).
     * Software has none: licences are not stock (PM decision 2026-10-22).
     */
    public function showsStockStatus(): bool
    {
        return $this->type !== ProductType::Software;
    }

    /**
     * The "Try" link of a software product: its brand's website (the
     * partner / referral link, entered in Brands; decision 2026-10-22) when
     * it is a valid https URL, kept exactly as entered. Null for any other
     * type, without a brand or an active brand, or without a valid link.
     * Needs "brand" loaded on lists (strict models).
     */
    public function partnerUrl(): ?string
    {
        if ($this->type !== ProductType::Software) {
            return null;
        }

        $url = $this->publicBrand()?->website;

        return is_string($url) && HttpsUrl::isValid(trim($url)) ? trim($url) : null;
    }

    /**
     * Images in display order (the first one is the main image).
     *
     * @return iterable<int, Media>
     */
    public function imageMedia(): iterable
    {
        return $this->getMedia(self::IMAGES);
    }

    /**
     * URL of a conversion of the main image; null without an image or while
     * the queued conversion hasn't run yet (the original is never public).
     */
    public function imageUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->getFirstMedia(self::IMAGES);

        return $media !== null && $media->hasGeneratedConversion($conversion) ? $media->getUrl($conversion) : null;
    }

    /**
     * Alt text of an image in the given (or current) locale, falling back to the
     * default locale and then to the product name.
     */
    public function imageAlt(Media $media, ?string $locale = null): string
    {
        $alt = (array) ($media->getCustomProperty('alt') ?? []);
        $locale ??= app()->getLocale();

        return (string) (($alt[$locale] ?? '') ?: ($alt[config('app.default_locale', 'uz')] ?? '') ?: $this->name);
    }
}
