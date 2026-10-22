<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * Catalog category (plan 03 D4: adjacency list, max depth 3, cached CategoryTree).
 *
 * Saving or deleting a model flushes the CategoryTree cache (again after the
 * surrounding transaction commits) and the sitemap. The public menu is built
 * from CategoryTree, so it follows too. Mass updates bypass model events: call
 * CategoryTree::flush() after them.
 *
 * Note: the attributes() relation shares its name with Eloquent's internal
 * $attributes array. Outside the class $category->attributes is the
 * relation; inside, use $this->attributes() or getRelationValue('attributes').
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $slug
 * @property string|null $icon
 * @property int $sort
 * @property bool $is_active
 */
#[Fillable(['parent_id', 'name', 'description', 'slug', 'icon', 'sort', 'is_active', 'meta_title', 'meta_description'])]
class Category extends Model implements HasMedia
{
    /** @use HasFactory<CategoryFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * Icon names of x-site.icon offered in the admin icon select.
     *
     * @var list<string>
     */
    public const ICONS = ['scan', 'print', 'term', 'label', 'rfid', 'pos', 'doc', 'wrench', 'shield', 'truck', 'grid'];

    /**
     * @var list<string>
     */
    public array $translatable = ['name', 'description', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        $flush = function (): void {
            CategoryTree::flush();
            DB::afterCommit(function (): void {
                CategoryTree::flush();
                Sitemap::flush();
                LlmsTxt::flush();
            });
        };

        static::saved($flush);
        static::deleted($flush);
        static::deleted(fn (Category $category) => $category->faqItems()->delete());
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
        return 'image';
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * The category's own attributes (not inherited ones), in pivot order.
     *
     * @return BelongsToMany<Attribute, $this, CategoryAttribute>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
            ->using(CategoryAttribute::class)
            ->withPivot(['is_required', 'is_filterable', 'sort'])
            ->orderByPivot('sort')
            ->orderBy('attributes.id');
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Products directly in this category (not its subcategories).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * FAQ of the category page (plan 04 T12), in admin order.
     *
     * @return MorphMany<FaqItem, $this>
     */
    public function faqItems(): MorphMany
    {
        return $this->morphMany(FaqItem::class, 'faqable')->orderBy('sort')->orderBy('id');
    }

    /**
     * Number of products directly in the category (trash not counted). Uses
     * products_count when the query loaded it (withCount('products')).
     */
    public function productsCount(): int
    {
        if (array_key_exists('products_count', $this->getAttributes())) {
            return (int) $this->getAttributes()['products_count'];
        }

        return $this->products()->count();
    }

    /**
     * Whether products belong to the category, including trashed ones (their
     * rows still reference it), which blocks deletion.
     */
    public function hasProducts(): bool
    {
        return Product::withTrashed()->where('category_id', $this->id)->exists();
    }
}
