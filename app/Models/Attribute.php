<?php

namespace App\Models;

use App\Enums\AttributeType;
use App\Enums\FilterMatch;
use App\Models\Concerns\StoresTranslations;
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
 * @property FilterMatch $filter_match
 * @property bool $filter_match_switch
 * @property int $sort
 */
#[Fillable(['code', 'name', 'unit', 'type', 'is_filterable', 'filter_match', 'filter_match_switch', 'sort'])]
class Attribute extends Model
{
    /** @use HasFactory<AttributeFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * Same as the column defaults, so a new model has them before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['filter_match' => 'all', 'filter_match_switch' => true];

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
            'filter_match' => FilterMatch::class,
            'filter_match_switch' => 'boolean',
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
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Whether products have values for this attribute (locks the type and blocks deletion).
     */
    public function hasValues(): bool
    {
        return $this->productValues()->exists();
    }

    /**
     * Plan 43 #26: an option label with the attribute's unit ("203 dpi",
     * "25 mm"), for the catalog facets and the card specs. Only a purely
     * numeric label ("203", "12.7", "100 x 150", "1/2") gets the unit of the
     * current locale; "USB 3.0", "Wi-Fi 6" and "OUT" stay as they are, and so
     * does a label that already ends with the unit as stored or as localized
     * in any supported locale ("25 mm", "25mm"; "3 dim" doesn't count for "m").
     * Plain text: the caller escapes it.
     */
    public function optionWithUnit(string $label): string
    {
        $unit = $this->unitLabel();

        if ($unit === null || trim($label) === '' || $this->endsWithUnit($label)) {
            return $label;
        }

        if (preg_match('/^[\d\s.,\-–\/x×]+$/u', $label) !== 1 || preg_match('/\d/', $label) !== 1) {
            return $label;
        }

        return trim($label).' '.$unit;
    }

    /**
     * Whether the label ends with the unit (stored code or a localized label),
     * case-insensitively, not as the tail of a longer word.
     */
    private function endsWithUnit(string $label): bool
    {
        $units = [(string) $this->unit];

        foreach (config('app.supported_locales', []) as $locale) {
            $key = "catalog.units.{$this->unit}";
            $units[] = Lang::has($key, $locale) ? (string) __($key, [], $locale) : (string) $this->unit;
        }

        foreach (array_unique(array_filter($units, fn (string $unit): bool => trim($unit) !== '')) as $unit) {
            if (preg_match('/(?<!\p{L})\s?'.preg_quote(trim($unit), '/').'$/iu', trim($label)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A formatted number with its unit: plural-aware for counted units
     * (catalog.unit_counts: "2 года", "5 лет", "1 month"), else "50 mm".
     * A fractional number takes the form of 2 ("12,5 месяца", "1.5 months").
     */
    public function withUnit(string $formattedNumber, float $number): string
    {
        $key = "catalog.unit_counts.{$this->unit}";

        if ($this->unit !== null && Lang::has($key)) {
            $count = floor($number) === $number ? (int) $number : 2;

            return trans_choice($key, $count, ['count' => $formattedNumber]);
        }

        $unit = $this->unitLabel();

        return $unit !== null ? $formattedNumber.' '.$unit : $formattedNumber;
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
