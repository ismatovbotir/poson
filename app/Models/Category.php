<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Catalog\CategoryTree;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * Catalog category (plan 03 D4: adjacency list, max depth 3, cached CategoryTree).
 *
 * Saving or deleting a model flushes the CategoryTree cache (again after the
 * surrounding transaction commits). Mass updates bypass model events: call
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
            DB::afterCommit(fn () => CategoryTree::flush());
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
     * Number of products in the category.
     *
     * TODO(3c): count products once the products table exists.
     */
    public function productsCount(): int
    {
        return 0;
    }

    /**
     * Whether products belong to the category (blocks deletion).
     *
     * TODO(3c): check the products table.
     */
    public function hasProducts(): bool
    {
        return $this->productsCount() > 0;
    }
}
