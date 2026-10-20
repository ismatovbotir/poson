<?php

namespace App\Models;

use App\Enums\AttributeType;
use Database\Factories\AttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;
use Spatie\Translatable\HasTranslations;

/**
 * Library attribute (spec): code, translatable name, unit, type and the
 * default is_filterable for new category assignments.
 *
 * @property int $id
 * @property string $code
 * @property string|null $unit
 * @property AttributeType $type
 * @property bool $is_filterable
 * @property int $sort
 */
#[Fillable(['code', 'name', 'unit', 'type', 'is_filterable', 'sort'])]
class Attribute extends Model
{
    /** @use HasFactory<AttributeFactory> */
    use HasFactory, HasTranslations;

    /**
     * @var list<string>
     */
    public array $translatable = ['name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'is_filterable' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<AttributeOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Categories that assign this attribute to their products.
     *
     * @return BelongsToMany<Category, $this, CategoryAttribute>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attribute')
            ->using(CategoryAttribute::class)
            ->withPivot(['is_required', 'is_filterable', 'sort']);
    }

    /**
     * Whether products have values for this attribute (locks the type and blocks deletion).
     *
     * TODO(3c): check product_attribute_values.
     */
    public function hasValues(): bool
    {
        return false;
    }

    /**
     * Unit for display: lang key catalog.units.<unit> when there is one
     * (pcs, months, mm, ...), otherwise the unit as stored (dpi, GHz, ...).
     */
    public function unitLabel(): ?string
    {
        if ($this->unit === null || $this->unit === '') {
            return null;
        }

        $key = "catalog.units.{$this->unit}";

        return Lang::has($key) ? __($key) : $this->unit;
    }
}
