<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Catalog\HttpsUrl;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * Product brand. The name is the same in every locale; the description and
 * the SEO meta title / description (plan 12) are translatable. Logo in the
 * medialibrary collection "logo".
 *
 * Public brand pages (/{locale}/brands) list the "listed" brands: active,
 * with at least one product visible on the site. Saving or deleting a
 * brand flushes the sitemap and llms.txt after the commit.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $website
 * @property bool $is_active
 * @property int $sort
 */
#[Fillable(['name', 'slug', 'description', 'meta_title', 'meta_description', 'website', 'is_active', 'sort'])]
class Brand extends Model implements HasMedia
{
    /** @use HasFactory<BrandFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * SEO meta lengths (plan 12): the brand form and SaveBrand enforce them.
     */
    public const META_TITLE_MAX = 60;

    public const META_DESCRIPTION_MAX = 160;

    /**
     * @var list<string>
     */
    public array $translatable = ['description', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        $flush = fn () => DB::afterCommit(function (): void {
            Sitemap::flush();
            LlmsTxt::flush();
        });

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
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function imageCollection(): string
    {
        return 'logo';
    }

    /**
     * @param  Builder<Brand>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * On the public site: active and with at least one visible product (Product::scopeVisible()).
     *
     * @param  Builder<Brand>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->active()->whereHas('products', fn (Builder $products) => $products->visible());
    }

    /**
     * Adds "visible_products_count": the brand's products visible on the site.
     *
     * @param  Builder<Brand>  $query
     */
    public function scopeWithVisibleProductsCount(Builder $query): void
    {
        $query->withCount(['products as visible_products_count' => fn (Builder $products) => $products->visible()]);
    }

    /**
     * Public sort: admin sort, then name.
     *
     * @param  Builder<Brand>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name')->orderBy('id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The website as the "Official website" link of the public brand page:
     * a valid https URL, and only for a brand without software products. A
     * software brand's website is the partner / referral link of the "Try"
     * button, which the site opens only through the tracked /go/ redirect
     * (ProductCtas), never as a plain link. One query.
     */
    public function publicWebsite(): ?string
    {
        $url = trim((string) $this->website);

        if (! HttpsUrl::isValid($url)) {
            return null;
        }

        $hasSoftware = Product::withTrashed()
            ->where('brand_id', $this->id)
            ->where('type', ProductType::Software->value)
            ->exists();

        return $hasSoftware ? null : $url;
    }

    /**
     * Whether products use this brand, including trashed ones (blocks deletion).
     */
    public function hasProducts(): bool
    {
        return Product::withTrashed()->where('brand_id', $this->id)->exists();
    }
}
