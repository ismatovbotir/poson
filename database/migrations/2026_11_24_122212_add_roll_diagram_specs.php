<?php

use App\Support\Site\HomeCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 38 (RD-9 – RD-11): the specs the roll diagrams need, on an existing catalog.
 *
 * Data only, idempotent and never overwriting; query builder and the frozen
 * literals below only (no seeders, Actions or env()). The uz literals equal
 * database/seeders/data/attributes.php and the product seed data, so a later
 * LaunchSeeder / CatalogSeeder run finds them and adds no duplicates.
 *
 *  - Attributes by code: an existing one is reused with its meta untouched,
 *    and gets values only when its type is the expected one.
 *  - Options matched by the trimmed, lower-cased uz value; a hex is written
 *    only where the option has none.
 *  - The category pivot only when missing.
 *  - A product value only when the product (by SKU, in the expected category,
 *    not deleted) has no value and no skip for that attribute.
 *  - has_missing_specs is only ever set to true (receipt-paper products still
 *    lacking the required paper_length), never to false.
 *  - A fresh or test database has no categories: nothing happens, the seeders
 *    create everything from the data files.
 *  - Logs SKUs, codes and counts only. The home cache is bumped after the
 *    commit, only when something changed.
 *
 * down() removes an attribute only while it is exactly what up() would have
 * created (literal names, unit and type, only the frozen options, values only on
 * the listed SKUs with the listed values, no product skips); otherwise it is kept and its code
 * logged, so admin data is never destroyed (RD-11).
 */
return new class extends Migration
{
    private const HEX_PATTERN = '/\A#[0-9A-F]{6}\z/';

    /**
     * @var array<string, array{name: array{uz: string, ru: string, en: string}, unit: string|null, type: string, is_filterable: bool, sort: int, category: string, is_required: bool, pivot_filterable: bool, options: list<array{uz: string, ru: string, en: string, hex?: string}>}>
     */
    private const ATTRIBUTES = [
        'ribbon_colour' => [
            'name' => ['uz' => 'Ribbon rangi', 'ru' => 'Цвет риббона', 'en' => 'Ribbon colour'],
            'unit' => null,
            'type' => 'select',
            'is_filterable' => true,
            'sort' => 535,
            'category' => 'ribbons',
            'is_required' => false,
            'pivot_filterable' => true,
            'options' => [
                ['uz' => 'Qora', 'ru' => 'Чёрный', 'en' => 'Black', 'hex' => '#111111'],
                ['uz' => 'Oq', 'ru' => 'Белый', 'en' => 'White', 'hex' => '#FFFFFF'],
                ['uz' => 'Qizil', 'ru' => 'Красный', 'en' => 'Red', 'hex' => '#C8102E'],
                ['uz' => 'Yashil', 'ru' => 'Зелёный', 'en' => 'Green', 'hex' => '#00843D'],
                ['uz' => 'Koʻk', 'ru' => 'Синий', 'en' => 'Blue', 'hex' => '#0033A0'],
                ['uz' => 'Sariq', 'ru' => 'Жёлтый', 'en' => 'Yellow', 'hex' => '#FFD100'],
                ['uz' => 'Oltin rang', 'ru' => 'Золотой', 'en' => 'Gold', 'hex' => '#C9A227'],
                ['uz' => 'Kumush rang', 'ru' => 'Серебристый', 'en' => 'Silver', 'hex' => '#A8A9AD'],
            ],
        ],
        'paper_length' => [
            'name' => ['uz' => 'Lenta uzunligi', 'ru' => 'Длина ленты', 'en' => 'Paper length'],
            'unit' => 'm',
            'type' => 'number',
            'is_filterable' => true,
            'sort' => 165,
            'category' => 'receipt-paper',
            'is_required' => true,
            'pivot_filterable' => true,
            'options' => [],
        ],
        'roll_core' => [
            'name' => ['uz' => 'Rulon gilzasi', 'ru' => 'Втулка рулона', 'en' => 'Roll core'],
            'unit' => null,
            'type' => 'select',
            'is_filterable' => false,
            'sort' => 166,
            'category' => 'receipt-paper',
            'is_required' => false,
            'pivot_filterable' => false,
            'options' => [
                ['uz' => 'Gilzali', 'ru' => 'С втулкой', 'en' => 'With core'],
                ['uz' => 'Gilzasiz', 'ru' => 'Без втулки', 'en' => 'Coreless'],
            ],
        ],
    ];

    /**
     * Product values: code => SKU => uz option value (select) or number.
     *
     * @var array<string, array<string, string|int>>
     */
    private const VALUES = [
        'ribbon_colour' => [
            '128' => 'Qora', '125' => 'Qora', '126' => 'Qora', '127' => 'Qora', '143' => 'Qora', '142' => 'Qora',
            '124' => 'Qora', '123' => 'Qora', '121' => 'Qora', '120' => 'Qora', '119' => 'Qora', '118' => 'Qora',
            '117' => 'Qora', '122' => 'Qora', '145' => 'Qora', '33' => 'Qora', '131' => 'Qora', '144' => 'Qora',
            '237' => 'Qora', '210' => 'Qora', '183' => 'Qora', '212' => 'Qora', '116' => 'Qora', '115' => 'Qora',
            '114' => 'Qora', '113' => 'Qora', '112' => 'Qora', '37' => 'Qora',
            '213' => 'Yashil',
            '214' => 'Oltin rang',
        ],
        'paper_length' => [
            '201' => 12, '167' => 12, '186' => 15, '184' => 30, '246' => 30, '57' => 50, '187' => 60, '47' => 80,
        ],
        'roll_core' => [
            '201' => 'Gilzasiz', '167' => 'Gilzali', '186' => 'Gilzali', '184' => 'Gilzali',
            '246' => 'Gilzali', '57' => 'Gilzali', '187' => 'Gilzali', '47' => 'Gilzali',
        ],
    ];

    /** @var array<string, int> */
    private array $counts = ['attributes' => 0, 'options' => 0, 'hex' => 0, 'pivots' => 0, 'values' => 0, 'missing_specs' => 0];

    public function up(): void
    {
        $this->counts = array_map(fn (): int => 0, $this->counts);
        $skipped = [];

        DB::transaction(function () use (&$skipped): void {
            $categories = DB::table('categories')->whereIn('slug', ['ribbons', 'receipt-paper'])->pluck('id', 'slug');

            foreach (self::ATTRIBUTES as $code => $definition) {
                $categoryId = $categories[$definition['category']] ?? null;

                if ($categoryId === null) {
                    $skipped[] = $code.':no-category';

                    continue;
                }

                $attribute = DB::table('attributes')->where('code', $code)->first(['id', 'type']);

                if ($attribute === null) {
                    $attributeId = $this->createAttribute($code, $definition);
                } elseif ($attribute->type !== $definition['type']) {
                    $skipped[] = $code.':type';

                    continue;
                } else {
                    $attributeId = (int) $attribute->id;
                }

                $options = $this->syncOptions($attributeId, $definition['options']);
                $this->attachCategory($attributeId, (int) $categoryId, $definition);
                $this->storeValues($code, $attributeId, (int) $categoryId, $definition['type'], $options, $skipped);

                if ($definition['is_required']) {
                    $this->flagMissingSpecs($attributeId, (int) $categoryId);
                }
            }
        });

        Log::info('roll diagram specs migration', ['counts' => $this->counts, 'skipped' => $skipped]);

        if (array_sum($this->counts) > 0) {
            HomeCache::bump();
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::ATTRIBUTES as $code => $definition) {
                $attribute = DB::table('attributes')->where('code', $code)->first();

                if ($attribute === null) {
                    continue;
                }

                if (! $this->isUntouched($code, $attribute, $definition)) {
                    Log::info('roll diagram specs migration: kept on rollback', ['code' => $code]);

                    continue;
                }

                DB::table('product_attribute_values')->where('attribute_id', $attribute->id)->delete();
                DB::table('category_attribute')->where('attribute_id', $attribute->id)->delete();
                DB::table('attribute_options')->where('attribute_id', $attribute->id)->delete();
                DB::table('attributes')->where('id', $attribute->id)->delete();
            }
        });

        HomeCache::bump();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createAttribute(string $code, array $definition): int
    {
        $now = Carbon::now();
        $this->counts['attributes']++;

        return (int) DB::table('attributes')->insertGetId([
            'code' => $code,
            'name' => $this->json($definition['name']),
            'unit' => $definition['unit'],
            'type' => $definition['type'],
            'is_filterable' => $definition['is_filterable'],
            'filter_match' => 'all',
            'filter_match_switch' => true,
            'sort' => $definition['sort'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Missing options are added after the existing ones; an existing option gets the hex only when it has none.
     *
     * @param  list<array<string, string>>  $options
     * @return array<string, int> lower-cased uz value => option id
     */
    private function syncOptions(int $attributeId, array $options): array
    {
        $ids = [];
        $existing = [];

        foreach (DB::table('attribute_options')->where('attribute_id', $attributeId)->orderBy('id')->get(['id', 'value', 'hex_color']) as $row) {
            $uz = json_decode((string) $row->value, true)['uz'] ?? null;
            $key = is_string($uz) ? mb_strtolower(trim($uz)) : null;

            if ($key !== null && ! isset($ids[$key])) {
                $ids[$key] = (int) $row->id;
                $existing[$key] = $row;
            }
        }

        $sort = (int) DB::table('attribute_options')->where('attribute_id', $attributeId)->max('sort');
        $now = Carbon::now();

        foreach ($options as $option) {
            $key = mb_strtolower(trim($option['uz']));
            $hex = isset($option['hex']) && preg_match(self::HEX_PATTERN, $option['hex']) === 1 ? $option['hex'] : null;

            if (isset($ids[$key])) {
                if ($hex !== null && ($existing[$key]->hex_color ?? null) === null) {
                    DB::table('attribute_options')->where('id', $ids[$key])->whereNull('hex_color')->update(['hex_color' => $hex, 'updated_at' => $now]);
                    $this->counts['hex']++;
                }

                continue;
            }

            $ids[$key] = (int) DB::table('attribute_options')->insertGetId([
                'attribute_id' => $attributeId,
                'value' => $this->json(['uz' => $option['uz'], 'ru' => $option['ru'], 'en' => $option['en']]),
                'hex_color' => $hex,
                'sort' => ++$sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->counts['options']++;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function attachCategory(int $attributeId, int $categoryId, array $definition): void
    {
        $pivot = DB::table('category_attribute')->where('category_id', $categoryId)->where('attribute_id', $attributeId);

        if ($pivot->exists()) {
            return;
        }

        DB::table('category_attribute')->insert([
            'category_id' => $categoryId,
            'attribute_id' => $attributeId,
            'is_required' => $definition['is_required'],
            'is_filterable' => $definition['pivot_filterable'],
            'sort' => (int) DB::table('category_attribute')->where('category_id', $categoryId)->max('sort') + 1,
        ]);
        $this->counts['pivots']++;
    }

    /**
     * @param  array<string, int>  $options
     * @param  list<string>  $skipped
     */
    private function storeValues(string $code, int $attributeId, int $categoryId, string $type, array $options, array &$skipped): void
    {
        foreach (self::VALUES[$code] as $sku => $value) {
            $product = DB::table('products')->where('sku', (string) $sku)->whereNull('deleted_at')->first(['id', 'category_id']);

            if ($product === null || (int) $product->category_id !== $categoryId) {
                $skipped[] = $code.':'.$sku;

                continue;
            }

            $taken = DB::table('product_attribute_values')->where('product_id', $product->id)->where('attribute_id', $attributeId)->exists()
                || DB::table('product_attribute_skips')->where('product_id', $product->id)->where('attribute_id', $attributeId)->exists();

            if ($taken) {
                continue;
            }

            $row = ['attribute_option_id' => null, 'value_number' => null];

            if ($type === 'select') {
                $optionId = $options[mb_strtolower((string) $value)] ?? null;

                if ($optionId === null) {
                    $skipped[] = $code.':'.$sku;

                    continue;
                }

                $row['attribute_option_id'] = $optionId;
            } else {
                $row['value_number'] = $value;
            }

            DB::table('product_attribute_values')->insert([
                'product_id' => $product->id,
                'attribute_id' => $attributeId,
                ...$row,
                'value_text' => null,
                'value_bool' => null,
                'is_key' => false,
                'sort' => (int) DB::table('product_attribute_values')->where('product_id', $product->id)->max('sort') + 1,
            ]);
            $this->counts['values']++;
        }
    }

    /**
     * Products of the category (and its subcategories) without a value for the
     * required attribute: has_missing_specs true (never set to false here).
     */
    private function flagMissingSpecs(int $attributeId, int $categoryId): void
    {
        $required = DB::table('category_attribute')->where('category_id', $categoryId)->where('attribute_id', $attributeId)->value('is_required');

        if (! $required) {
            return;
        }

        $categoryIds = [$categoryId];

        for ($level = [$categoryId], $depth = 0; $level !== [] && $depth < 5; $depth++) {
            $level = DB::table('categories')->whereIn('parent_id', $level)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $categoryIds = [...$categoryIds, ...$level];
        }

        $this->counts['missing_specs'] += DB::table('products')
            ->whereIn('category_id', $categoryIds)
            ->whereNull('deleted_at')
            ->where('has_missing_specs', false)
            ->whereNotExists(fn ($values) => $values->from('product_attribute_values')->whereColumn('product_attribute_values.product_id', 'products.id')->where('product_attribute_values.attribute_id', $attributeId))
            ->whereNotExists(fn ($skips) => $skips->from('product_attribute_skips')->whereColumn('product_attribute_skips.product_id', 'products.id')->where('product_attribute_skips.attribute_id', $attributeId))
            ->update(['has_missing_specs' => true]);
    }

    /**
     * Whether the attribute is still exactly what up() creates: literal meta, only
     * frozen options, values only on the listed SKUs with the listed values.
     *
     * @param  array<string, mixed>  $definition
     */
    private function isUntouched(string $code, object $attribute, array $definition): bool
    {
        if ($attribute->type !== $definition['type'] || $attribute->unit !== $definition['unit'] || json_decode((string) $attribute->name, true) !== $definition['name']) {
            return false;
        }

        if (DB::table('product_attribute_skips')->where('attribute_id', $attribute->id)->exists()) {
            return false;
        }

        $frozen = array_map(fn (array $option): string => $option['uz'], $definition['options']);
        $options = [];

        foreach (DB::table('attribute_options')->where('attribute_id', $attribute->id)->get(['id', 'value']) as $row) {
            $uz = json_decode((string) $row->value, true)['uz'] ?? null;

            if (! in_array($uz, $frozen, true)) {
                return false;
            }

            $options[(int) $row->id] = $uz;
        }

        $values = DB::table('product_attribute_values')
            ->join('products', 'products.id', '=', 'product_attribute_values.product_id')
            ->where('product_attribute_values.attribute_id', $attribute->id)
            ->get(['products.sku', 'product_attribute_values.attribute_option_id', 'product_attribute_values.value_number', 'product_attribute_values.value_text', 'product_attribute_values.value_bool']);

        foreach ($values as $value) {
            $expected = self::VALUES[$code][$value->sku] ?? null;

            if ($expected === null || $value->value_text !== null || $value->value_bool !== null) {
                return false;
            }

            $matches = $definition['type'] === 'select'
                ? ($options[(int) $value->attribute_option_id] ?? null) === $expected
                : $value->value_number !== null && (float) $value->value_number === (float) $expected;

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};
