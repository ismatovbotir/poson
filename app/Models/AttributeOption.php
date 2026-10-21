<?php

namespace App\Models;

use App\Models\Concerns\StoresTranslations;
use Database\Factories\AttributeOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A value of a select / multiselect attribute (translatable).
 *
 * @property int $id
 * @property int $attribute_id
 * @property int $sort
 */
#[Fillable(['attribute_id', 'value', 'sort'])]
class AttributeOption extends Model
{
    /** @use HasFactory<AttributeOptionFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * @var list<string>
     */
    public array $translatable = ['value'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * Whether product values use this option (blocks deletion).
     *
     * TODO(3c): check product_attribute_values.
     */
    public function isInUse(): bool
    {
        return false;
    }
}
