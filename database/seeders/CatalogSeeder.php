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
 * Create-only (plan 16 S1; production data is edited in admin): categories
 * match by slug, attributes by code, options by their uz value, brands by
 * slug, roll types by their labels / core pair. A match is left exactly as it
 * is (name, sort, parent, is_active, filters, pivot flags); only missing rows
 * are inserted, so re-running never overwrites admin edits and a new category
 * is attached under its (possibly admin-moved) parent. Categories marked
 * 'is_active' => false in the data are created inactive. Pivot sort follows
 * the attribute order for new assignments.
 *
 * Known limitation: a row an admin deleted (option, category-attribute pivot,
 * brand, roll type, category) is created again on the next seed, because the
 * seeder cannot tell a deletion from a missing row.
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
        // Brands of the real product import (plan 16).
        'Poson', 'Gainscha', 'HPRT', 'Bixolon', 'Henex', 'Mertech', 'Posbank', 'P2C', 'Citaq', 'Sunany', 'Rongta',
        'Shtrix-Print', 'Jassway', 'EPG', 'Pospole', 'Printronix', 'Argox', 'Asianwell', 'Honorway', 'Netum', 'SPRT',
        'Afanda', 'BDB', 'Nishon', 'Lenovo', 'POS System',
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
            $category = Category::query()->where('slug', $node['slug'])->first();

            if ($category === null) {
                $category = new Category;
                $category->slug = $node['slug'];

                foreach (['name', 'description', 'meta_title', 'meta_description'] as $key) {
                    $category->storeTranslations($key, $node[$key] ?? []);
                }

                $category->fill([
                    'parent_id' => $parentId,
                    'icon' => $node['icon'] ?? null,
                    'sort' => $node['sort'],
                    'is_active' => $node['is_active'] ?? true,
                ])->save();
            }

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
            $attribute = Attribute::query()->where('code', $row['code'])->first();

            if ($attribute === null) {
                $attribute = new Attribute;
                $attribute->code = $row['code'];
                $attribute->storeTranslations('name', $row['name']);
                $attribute->fill([
                    'unit' => $row['unit'],
                    'type' => $type,
                    'is_filterable' => $type->canBeFilterable() && $row['is_filterable'],
                    'sort' => $row['sort'],
                ])->save();
            }

            $this->seedOptions($attribute, $row['options'] ?? []);

            $assigned = $attribute->categories()->pluck('categories.id')->all();

            foreach ($row['categories'] ?? [] as $slug => $flags) {
                if (! isset($categoryIds[$slug])) {
                    throw new \RuntimeException("Attribute {$row['code']}: unknown category slug {$slug}.");
                }

                if (in_array($categoryIds[$slug], $assigned, true)) {
                    continue;
                }

                $attribute->categories()->attach($categoryIds[$slug], [
                    'is_required' => $flags['is_required'],
                    'is_filterable' => $type->canBeFilterable() && $flags['is_filterable'],
                    'sort' => $position + 1,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $values
     */
    private function seedOptions(Attribute $attribute, array $values): void
    {
        $existing = $attribute->options()->get()
            ->map(fn (AttributeOption $option): string => mb_strtolower((string) $option->getTranslation('value', 'uz', false)))
            ->all();

        foreach (array_values($values) as $index => $value) {
            if (in_array(mb_strtolower($value['uz']), $existing, true)) {
                continue;
            }

            $option = new AttributeOption(['attribute_id' => $attribute->id]);
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
