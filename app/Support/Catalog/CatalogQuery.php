<?php

namespace App\Support\Catalog;

use App\Enums\AttributeType;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use App\Support\NumberFormat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Public catalog listing (plan 03 §3, T17): the published products of a
 * category and its visible subcategories (or of the whole catalog), the
 * filters from the URL, the sort, and the filter facets.
 *
 * URL parameters (raw input is sanitized here, unknown values are ignored):
 * - brand[]               slugs of active brands
 * - stock[]               StockStatus values
 * - price_min, price_max  whole soʻm (the query compares tiyin)
 * - f[code][]             option ids of a select / multiselect attribute,
 *                         "1" for a boolean attribute
 * - f[code][min|max]      bounds of a number attribute
 * - sort                  popular | price_asc | price_desc | new
 *
 * Values of one attribute (or brand, or stock) are OR-ed; different
 * attributes and filters are AND-ed. Attribute filters exist only on a
 * category listing: the attributes the category or its ancestors assign
 * with is_filterable (never text). Facet counts come from the listing's
 * base set (no filters applied); options without products are hidden.
 *
 * Brand rule: only the Retail price ("price") is filtered, sorted and shown.
 */
class CatalogQuery
{
    public const SORTS = ['popular', 'price_asc', 'price_desc', 'new'];

    public const DEFAULT_SORT = 'popular';

    /**
     * Longest accepted list per parameter (keeps queries bounded).
     */
    private const MAX_VALUES = 50;

    /** @var list<string> */
    public readonly array $brandSlugs;

    /** @var list<StockStatus> */
    public readonly array $stock;

    public readonly ?int $priceMin;

    public readonly ?int $priceMax;

    public readonly string $sort;

    /**
     * Raw f[...] input, validated against the filterable attributes on use.
     *
     * @var array<mixed>
     */
    private array $rawAttributeFilters;

    /** @var array<string, ResolvedAttribute>|null */
    private ?array $filterable = null;

    /** @var array<string, array{attribute: Attribute, options: list<int>, min: ?string, max: ?string, flag: bool}>|null */
    private ?array $attributeFilters = null;

    /** @var array<string, int>|null slug => id */
    private ?array $brandIds = null;

    public function __construct(
        public readonly ?Category $category = null,
        mixed $brand = [],
        mixed $stock = [],
        mixed $priceMin = null,
        mixed $priceMax = null,
        mixed $attributeFilters = [],
        mixed $sort = self::DEFAULT_SORT,
        private ?ProductAttributeResolver $resolver = null,
    ) {
        // Inputs are cut to MAX_VALUES before any work on them.
        $this->brandSlugs = array_values(array_unique(array_filter(
            static::bounded($brand),
            fn (mixed $slug): bool => is_string($slug) && $slug !== '' && strlen($slug) <= 255,
        )));

        $this->stock = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?StockStatus => is_string($value) ? StockStatus::tryFrom($value) : null,
            static::bounded($stock),
        )), SORT_REGULAR));

        $this->priceMin = static::wholeSums($priceMin);
        $this->priceMax = static::wholeSums($priceMax);
        $this->rawAttributeFilters = array_slice(is_array($attributeFilters) ? $attributeFilters : [], 0, self::MAX_VALUES, true);
        $this->sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
        $this->resolver ??= app(ProductAttributeResolver::class);
    }

    /**
     * The first MAX_VALUES items of an array input; anything else is empty.
     *
     * @return list<mixed>
     */
    private static function bounded(mixed $values): array
    {
        return is_array($values) ? array_values(array_slice($values, 0, self::MAX_VALUES)) : [];
    }

    /**
     * Whole soʻm from a URL value ("1 250 000" or "1250000"), null when empty or invalid.
     */
    public static function wholeSums(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        $tiyin = is_string($value) ? Money::sumsToTiyin($value) : null;

        return $tiyin === null ? null : intdiv($tiyin, 100);
    }

    /**
     * Published products of the listing, before filters.
     *
     * @return Builder<Product>
     */
    public function baseQuery(): Builder
    {
        $query = Product::query()->visible();

        if ($this->category !== null) {
            $query->whereIn('products.category_id', CategoryTree::visibleSubtreeIds($this->category->id));
        }

        return $query;
    }

    /**
     * The listing with every filter applied.
     *
     * @return Builder<Product>
     */
    public function filteredQuery(): Builder
    {
        $query = $this->baseQuery();

        // Unknown slugs are ignored, like other invalid filter values.
        if ($this->brandIds() !== []) {
            $query->whereIn('products.brand_id', array_values($this->brandIds()));
        }

        if ($this->stock !== []) {
            $query->whereIn('products.stock_status', array_map(fn (StockStatus $status): string => $status->value, $this->stock));
        }

        if ($this->priceMin !== null) {
            $query->where('products.price', '>=', $this->priceMin * 100);
        }

        if ($this->priceMax !== null) {
            $query->where('products.price', '<=', $this->priceMax * 100);
        }

        foreach ($this->attributeFilters() as $filter) {
            $query->whereExists(function (QueryBuilder $values) use ($filter): void {
                $values->selectRaw('1')
                    ->from('product_attribute_values')
                    ->whereColumn('product_attribute_values.product_id', 'products.id')
                    ->where('product_attribute_values.attribute_id', $filter['attribute']->id);

                match ($filter['attribute']->type) {
                    AttributeType::Select, AttributeType::Multiselect => $values->whereIn('product_attribute_values.attribute_option_id', $filter['options']),
                    AttributeType::Boolean => $values->where('product_attribute_values.value_bool', true),
                    default => $values
                        ->when($filter['min'] !== null, fn (QueryBuilder $range) => $range->where('product_attribute_values.value_number', '>=', $filter['min']))
                        ->when($filter['max'] !== null, fn (QueryBuilder $range) => $range->where('product_attribute_values.value_number', '<=', $filter['max'])),
                };
            });
        }

        return $query;
    }

    /**
     * One page of the filtered, sorted listing with the card relations loaded.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        $query = $this->filteredQuery()->with(ProductCards::EAGER_LOADS);

        match ($this->sort) {
            'price_asc' => $query->orderByRaw('price is null')->orderBy('products.price')->orderBy('products.id'),
            'price_desc' => $query->orderByRaw('price is null')->orderByDesc('products.price')->orderBy('products.id'),
            'new' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.is_featured')->orderBy('products.sort')->orderBy('products.id'),
        };

        return $query->paginate($perPage);
    }

    /**
     * Filterable attributes of the category (and its ancestors), by code.
     *
     * @return array<string, ResolvedAttribute>
     */
    public function filterableAttributes(): array
    {
        if ($this->filterable !== null) {
            return $this->filterable;
        }

        $this->filterable = [];

        if ($this->category === null) {
            return $this->filterable;
        }

        foreach ($this->resolver->categoryAttributes($this->category->id) as $row) {
            if ($row->filterable && $row->attribute->type->canBeFilterable()) {
                $this->filterable[$row->attribute->code] = $row;
            }
        }

        return $this->filterable;
    }

    /**
     * Valid attribute filters from f[...], by code.
     *
     * @return array<string, array{attribute: Attribute, options: list<int>, min: ?string, max: ?string, flag: bool}>
     */
    public function attributeFilters(): array
    {
        if ($this->attributeFilters !== null) {
            return $this->attributeFilters;
        }

        $this->attributeFilters = [];

        foreach ($this->filterableAttributes() as $code => $row) {
            $input = $this->rawAttributeFilters[$code] ?? null;

            if (! is_array($input)) {
                continue;
            }

            $attribute = $row->attribute;
            $filter = ['attribute' => $attribute, 'options' => [], 'min' => null, 'max' => null, 'flag' => false];

            if ($attribute->type->hasOptions()) {
                $known = $attribute->options->pluck('id')->all();
                $filter['options'] = array_values(array_unique(array_filter(
                    array_map(fn (mixed $id): int => is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0, static::bounded($input)),
                    fn (int $id): bool => in_array($id, $known, true),
                )));

                if ($filter['options'] === []) {
                    continue;
                }
            } elseif ($attribute->type === AttributeType::Boolean) {
                if (! in_array('1', array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', static::bounded($input)), true)) {
                    continue;
                }

                $filter['flag'] = true;
            } else {
                $filter['min'] = static::number($input['min'] ?? null);
                $filter['max'] = static::number($input['max'] ?? null);

                if ($filter['min'] === null && $filter['max'] === null) {
                    continue;
                }
            }

            $this->attributeFilters[$code] = $filter;
        }

        return $this->attributeFilters;
    }

    /**
     * Number of active filter values: each brand, stock status, option and
     * flag counts once, a price or number range once.
     */
    public function activeFilterCount(): int
    {
        $count = count($this->brandIds()) + count($this->stock);
        $count += ($this->priceMin !== null || $this->priceMax !== null) ? 1 : 0;

        foreach ($this->attributeFilters() as $filter) {
            $count += $filter['options'] !== [] ? count($filter['options']) : 1;
        }

        return $count;
    }

    /**
     * Robots meta (PM decision 3d review): any filter or a non-default sort
     * keeps the page out of the index; the canonical stays the clean URL.
     */
    public function robots(): ?string
    {
        return $this->activeFilterCount() > 0 || $this->sort !== self::DEFAULT_SORT ? 'noindex,follow' : null;
    }

    /**
     * Facets from the base set: brands, stock statuses, the price range and
     * the attribute filters, without empty options.
     *
     * @return array{
     *     brands: list<array{slug: string, name: string, count: int, checked: bool}>,
     *     stock: list<array{value: string, label: string, count: int, checked: bool}>,
     *     price: array{min: ?int, max: ?int},
     *     attributes: list<array{code: string, name: string, type: AttributeType, unit: ?string, options: list<array{id: int, label: string, count: int, checked: bool}>, min: ?string, max: ?string, count: int, checked: bool, from: ?string, to: ?string}>
     * }
     */
    public function facets(): array
    {
        return [
            'brands' => $this->brandFacet(),
            'stock' => $this->stockFacet(),
            'price' => $this->priceFacet(),
            'attributes' => $this->attributeFacets(),
        ];
    }

    /**
     * Chips of the active filters; "remove" holds the arguments of the
     * component's removeFilter().
     *
     * @return list<array{label: string, remove: array{0: string, 1: string, 2: string}}>
     */
    public function activeFilters(): array
    {
        $chips = [];

        if ($this->brandSlugs !== []) {
            $names = Brand::query()->active()->whereIn('slug', array_keys($this->brandIds()))->pluck('name', 'slug');

            foreach ($names as $slug => $name) {
                $chips[] = ['label' => (string) $name, 'remove' => ['brand', (string) $slug, '']];
            }
        }

        foreach ($this->stock as $status) {
            $chips[] = ['label' => __('site.stock.'.$status->value), 'remove' => ['stock', $status->value, '']];
        }

        if ($this->priceMin !== null) {
            $chips[] = ['label' => __('site.catalog.chip_price_min', ['amount' => Money::format($this->priceMin * 100)]), 'remove' => ['price_min', '', '']];
        }

        if ($this->priceMax !== null) {
            $chips[] = ['label' => __('site.catalog.chip_price_max', ['amount' => Money::format($this->priceMax * 100)]), 'remove' => ['price_max', '', '']];
        }

        foreach ($this->attributeFilters() as $code => $filter) {
            $attribute = $filter['attribute'];
            $name = (string) $attribute->name;

            if ($filter['options'] !== []) {
                $options = $attribute->options->keyBy('id');

                foreach ($filter['options'] as $optionId) {
                    $chips[] = ['label' => $name.': '.$options[$optionId]->value, 'remove' => ['option', $code, (string) $optionId]];
                }
            } elseif ($filter['flag']) {
                $chips[] = ['label' => $name, 'remove' => ['attribute', $code, '']];
            } else {
                $chips[] = ['label' => $name.': '.static::range($attribute, $filter['min'], $filter['max']), 'remove' => ['attribute', $code, '']];
            }
        }

        return $chips;
    }

    /**
     * The listing's query string without the page (pager links).
     *
     * @return array<string, mixed>
     */
    public function queryParameters(): array
    {
        $attributes = [];

        foreach ($this->attributeFilters() as $code => $filter) {
            $attributes[$code] = match (true) {
                $filter['options'] !== [] => $filter['options'],
                $filter['flag'] => ['1'],
                default => array_filter(['min' => $filter['min'], 'max' => $filter['max']], fn (?string $value): bool => $value !== null),
            };
        }

        return array_filter([
            'brand' => array_keys($this->brandIds()),
            'stock' => array_map(fn (StockStatus $status): string => $status->value, $this->stock),
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'f' => $attributes,
            'sort' => $this->sort !== self::DEFAULT_SORT ? $this->sort : null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Brand ids of the brand[] slugs that exist, keyed by slug.
     *
     * @return array<string, int>
     */
    private function brandIds(): array
    {
        if ($this->brandIds === null) {
            $this->brandIds = $this->brandSlugs === []
                ? []
                : Brand::query()->active()->whereIn('slug', $this->brandSlugs)->pluck('id', 'slug')->map(fn ($id): int => (int) $id)->all();
        }

        return $this->brandIds;
    }

    /**
     * @return list<array{slug: string, name: string, count: int, checked: bool}>
     */
    private function brandFacet(): array
    {
        $counts = $this->baseQuery()
            ->whereNotNull('products.brand_id')
            ->groupBy('products.brand_id')
            ->selectRaw('products.brand_id as brand_id, count(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'brand_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $selected = $this->brandIds();

        return Brand::query()
            ->active()
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Brand $brand): array => [
                'slug' => $brand->slug,
                'name' => $brand->name,
                'count' => (int) $counts[$brand->id],
                'checked' => isset($selected[$brand->slug]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, count: int, checked: bool}>
     */
    private function stockFacet(): array
    {
        $counts = $this->baseQuery()
            ->groupBy('products.stock_status')
            ->selectRaw('products.stock_status as stock_status, count(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'stock_status');

        $facet = [];

        foreach (StockStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            if ($count > 0) {
                $facet[] = [
                    'value' => $status->value,
                    'label' => __('site.stock.'.$status->value),
                    'count' => $count,
                    'checked' => in_array($status, $this->stock, true),
                ];
            }
        }

        return $facet;
    }

    /**
     * Lowest and highest Retail price of the base set, in whole soʻm.
     *
     * @return array{min: ?int, max: ?int}
     */
    private function priceFacet(): array
    {
        $range = $this->baseQuery()
            ->whereNotNull('products.price')
            ->toBase()
            ->selectRaw('min(products.price) as min_price, max(products.price) as max_price')
            ->first();

        return [
            'min' => $range?->min_price !== null ? intdiv((int) $range->min_price, 100) : null,
            'max' => $range?->max_price !== null ? (int) ceil(((int) $range->max_price) / 100) : null,
        ];
    }

    /**
     * @return list<array{code: string, name: string, type: AttributeType, unit: ?string, options: list<array{id: int, label: string, count: int, checked: bool}>, min: ?string, max: ?string, count: int, checked: bool, from: ?string, to: ?string}>
     */
    private function attributeFacets(): array
    {
        $rows = $this->filterableAttributes();

        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn (ResolvedAttribute $row): int => $row->attribute->id, array_values($rows));
        $productIds = $this->baseQuery()->select('products.id');

        $values = fn () => DB::table('product_attribute_values')
            ->whereIn('attribute_id', $ids)
            ->whereIn('product_id', (clone $productIds)->toBase());

        $optionCounts = $values()
            ->whereNotNull('attribute_option_id')
            ->groupBy('attribute_option_id')
            ->selectRaw('attribute_option_id, count(distinct product_id) as aggregate')
            ->pluck('aggregate', 'attribute_option_id');

        $ranges = $values()
            ->whereNotNull('value_number')
            ->groupBy('attribute_id')
            ->selectRaw('attribute_id, min(value_number) as min_value, max(value_number) as max_value')
            ->get()
            ->keyBy('attribute_id');

        $flags = $values()
            ->where('value_bool', true)
            ->groupBy('attribute_id')
            ->selectRaw('attribute_id, count(distinct product_id) as aggregate')
            ->pluck('aggregate', 'attribute_id');

        $active = $this->attributeFilters();
        $facets = [];

        foreach ($rows as $code => $row) {
            $attribute = $row->attribute;
            $filter = $active[$code] ?? null;
            $facet = [
                'code' => $code,
                'name' => (string) $attribute->name,
                'type' => $attribute->type,
                'unit' => $attribute->unitLabel(),
                'options' => [],
                'min' => null,
                'max' => null,
                'count' => 0,
                'checked' => $filter !== null && $filter['flag'],
                'from' => $filter['min'] ?? null,
                'to' => $filter['max'] ?? null,
            ];

            if ($attribute->type->hasOptions()) {
                foreach ($attribute->options as $option) {
                    $count = (int) ($optionCounts[$option->id] ?? 0);

                    if ($count > 0) {
                        $facet['options'][] = [
                            'id' => $option->id,
                            'label' => (string) $option->value,
                            'count' => $count,
                            'checked' => in_array($option->id, $filter['options'] ?? [], true),
                        ];
                    }
                }

                if ($facet['options'] === []) {
                    continue;
                }
            } elseif ($attribute->type === AttributeType::Boolean) {
                $facet['count'] = (int) ($flags[$attribute->id] ?? 0);

                if ($facet['count'] === 0) {
                    continue;
                }
            } else {
                $range = $ranges->get($attribute->id);

                if ($range === null || (float) $range->min_value === (float) $range->max_value) {
                    continue;
                }

                $facet['min'] = static::trimNumber((string) $range->min_value);
                $facet['max'] = static::trimNumber((string) $range->max_value);
            }

            $facets[] = $facet;
        }

        return $facets;
    }

    /**
     * "50–100 mm", "≥ 50 mm" or "≤ 100 mm", in the locale's number format;
     * a counted unit agrees with the last number ("1–2 года").
     */
    private static function range(Attribute $attribute, ?string $min, ?string $max): string
    {
        $last = $max ?? $min ?? '0';
        $numbers = match (true) {
            $min !== null && $max !== null => NumberFormat::format($min).'–'.NumberFormat::format($max),
            $min !== null => '≥ '.NumberFormat::format($min),
            default => '≤ '.NumberFormat::format((string) $max),
        };

        return $attribute->withUnit($numbers, (float) $last);
    }

    /**
     * A decimal from the URL ("12.5", "12,5"), null when empty or invalid.
     */
    private static function number(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        return preg_match('/^\d{1,10}(\.\d{1,4})?$/', $value) === 1 ? $value : null;
    }

    /**
     * "12.5000" → "12.5", "3.0" → "3".
     */
    private static function trimNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
