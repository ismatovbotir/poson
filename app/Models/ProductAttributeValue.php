<?php

namespace App\Models;

use App\Models\Concerns\StoresTranslations;
use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * One spec value of a product. The column used depends on the attribute
 * type: value_text (text, translatable), value_number, value_bool, or
 * attribute_option_id (select; multiselect = one row per option).
 *
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property int|null $attribute_option_id
 * @property string|null $value_number decimal as a string ("12.5000")
 * @property bool|null $value_bool
 * @property bool $is_key
 * @property int $sort
 */
#[Fillable(['product_id', 'attribute_id', 'attribute_option_id', 'value_text', 'value_number', 'value_bool', 'is_key', 'sort'])]
class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    public array $translatable = ['value_text'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_bool' => 'boolean',
            'is_key' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @return BelongsTo<AttributeOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id');
    }

    /**
     * The number without trailing zeros ("12.5000" → "12.5", "3.0000" → "3").
     */
    public function numberForDisplay(): ?string
    {
        if ($this->value_number === null) {
            return null;
        }

        $number = (string) $this->value_number;

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
