<?php

namespace Database\Seeders;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog base data (plan 03 T9): the category tree from data/categories.php,
 * the attribute library with options and category assignments from
 * data/attributes.php, a starter list of brands and the label roll types
 * from data/roll_types.php (plan 08).
 *
 * Idempotent: categories match by slug, attributes by code, options by
 * their uz value, brands by slug (a brand's website is only set on create),
 * roll types by their labels / core pair (only created). Pivot sort follows the attribute order.
 */
class CatalogSeeder extends Seeder
{
    /**
     * Brands carried (names only; descriptions and logos are added in admin).
     *
     * @var list<string>
     */
    public const BRANDS = [
        'Zebra', 'Honeywell', 'Datalogic', 'Urovo', 'Newland', 'Godex', 'TSC', 'Xprinter', 'Sunmi', 'Atol',
        // Software vendors of the demo software products.
        'MoySklad', 'Billz', 'Ox', 'AliPos', 'Hippo', 'Pospoint', 'Rahmat',
    ];

    /**
     * Official sites of the software vendors (docs/pm/notes/software-facts.md,
     * user answer for AliPos), set only when the brand is created. For a
     * software brand the website is also the "Try" link (ProductCtas): admins
     * replace it with the partner / referral link in Brands.
     *
     * @var array<string, string>
     */
    public const BRAND_WEBSITES = [
        'MoySklad' => 'https://moysklad.uz',
        'Billz' => 'https://billz.io',
        'Ox' => 'https://oxapp.io',
        'AliPos' => 'https://alipos.uz',
        'Hippo' => 'https://hippo.uz',
        'Pospoint' => 'https://pospoint.uz',
        'Rahmat' => 'https://rhmt.uz/pos/virtual-kassa/',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $categories = $this->seedCategories(require database_path('seeders/data/categories.php'));
            $this->seedAttributes(require database_path('seeders/data/attributes.php'), $categories);
            $this->seedBrands();
            $this->seedRollTypes(require database_path('seeders/data/roll_types.php'));
        });

        // Model events may be muted (WithoutModelEvents in DatabaseSeeder).
        CategoryTree::flush();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, int> slug => id
     */
    private function seedCategories(array $nodes, ?int $parentId = null): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $category = Category::query()->firstOrNew(['slug' => $node['slug']]);

            foreach (['name', 'description', 'meta_title', 'meta_description'] as $key) {
                $category->storeTranslations($key, $node[$key] ?? []);
            }

            $category->fill([
                'parent_id' => $parentId,
                'icon' => $node['icon'] ?? null,
                'sort' => $node['sort'],
                'is_active' => true,
            ])->save();

            $ids[$category->slug] = $category->id;
            $ids += $this->seedCategories($node['children'] ?? [], $category->id);
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $categoryIds  slug => id
     */
    private function seedAttributes(array $rows, array $categoryIds): void
    {
        foreach (array_values($rows) as $position => $row) {
            $type = AttributeType::from($row['type']);
            $attribute = Attribute::query()->firstOrNew(['code' => $row['code']]);
            $attribute->storeTranslations('name', $row['name']);
            $attribute->fill([
                'unit' => $row['unit'],
                'type' => $type,
                'is_filterable' => $type->canBeFilterable() && $row['is_filterable'],
                'sort' => $row['sort'],
            ])->save();

            $this->seedOptions($attribute, $row['options'] ?? []);

            foreach ($row['categories'] ?? [] as $slug => $flags) {
                if (! isset($categoryIds[$slug])) {
                    throw new \RuntimeException("Attribute {$row['code']}: unknown category slug {$slug}.");
                }

                $attribute->categories()->syncWithoutDetaching([$categoryIds[$slug] => [
                    'is_required' => $flags['is_required'],
                    'is_filterable' => $type->canBeFilterable() && $flags['is_filterable'],
                    'sort' => $position + 1,
                ]]);
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $values
     */
    private function seedOptions(Attribute $attribute, array $values): void
    {
        $existing = $attribute->options()->get()->keyBy(fn (AttributeOption $option): string => mb_strtolower((string) $option->getTranslation('value', 'uz', false)));

        foreach (array_values($values) as $index => $value) {
            $option = $existing->get(mb_strtolower($value['uz'])) ?? new AttributeOption(['attribute_id' => $attribute->id]);
            $option->storeTranslations('value', $value);
            $option->sort = $index + 1;
            $option->save();
        }
    }

    /**
     * @param  list<array{core: string, labels: int}>  $rows
     */
    private function seedRollTypes(array $rows): void
    {
        foreach (array_values($rows) as $index => $row) {
            RollType::query()->firstOrCreate(
                ['labels_per_roll' => $row['labels'], 'core_diameter_mm' => number_format((float) $row['core'], 1, '.', '')],
                ['is_active' => true, 'sort' => $index + 1],
            );
        }
    }

    private function seedBrands(): void
    {
        foreach (self::BRANDS as $index => $name) {
            Brand::query()->firstOrCreate(['slug' => Slug::make($name)], [
                'name' => $name,
                'website' => self::BRAND_WEBSITES[$name] ?? null,
                'is_active' => true,
                'sort' => $index + 1,
            ]);
        }
    }
}
