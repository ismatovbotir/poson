<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * Product brand. The name is the same in every locale; the description is
 * translatable. Logo in the medialibrary collection "logo".
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $website
 * @property bool $is_active
 * @property int $sort
 */
#[Fillable(['name', 'slug', 'description', 'website', 'is_active', 'sort'])]
class Brand extends Model implements HasMedia
{
    /** @use HasFactory<BrandFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * @var list<string>
     */
    public array $translatable = ['description'];

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
     * Whether products use this brand (blocks deletion).
     *
     * TODO(3c): check the products table.
     */
    public function hasProducts(): bool
    {
        return false;
    }
}
