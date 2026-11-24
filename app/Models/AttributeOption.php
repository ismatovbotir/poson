<?php

namespace App\Models;

use App\Models\Concerns\StoresTranslations;
use App\Support\HexColor;
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
 * @property string|null $hex_color raw column; read it only through hexColor()
 */
#[Fillable(['attribute_id', 'value', 'hex_color', 'sort'])]
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
     * The display colour "#RRGGBB" (plan 38), re-validated on every read:
     * null when empty or not a valid hex (RD-1).
     */
    public function hexColor(): ?string
    {
        return HexColor::normalize($this->getAttribute('hex_color'));
    }

    /**
     * Whether product values use this option (blocks deletion).
     */
    public function isInUse(): bool
    {
        return ProductAttributeValue::query()->where('attribute_option_id', $this->id)->exists();
    }
}
