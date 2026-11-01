<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\Navigation;
use Database\Seeders\CatalogSeeder;

it('seeds the catalog idempotently', function () {
    $this->seed(CatalogSeeder::class);

    $counts = fn (): array => [
        Category::query()->count(),
        Attribute::query()->count(),
        AttributeOption::query()->count(),
        DB::table('category_attribute')->count(),
        Brand::query()->count(),
    ];
    $first = $counts();

    $this->seed(CatalogSeeder::class);

    // Counts follow the data files (the content writer may extend them).
    $countNodes = function (array $nodes) use (&$countNodes): int {
        return array_sum(array_map(fn (array $node): int => 1 + $countNodes($node['children'] ?? []), $nodes));
    };

    expect($counts())->toBe($first)
        ->and($first[0])->toBe($countNodes(require database_path('seeders/data/categories.php')))
        ->and($first[1])->toBe(count(require database_path('seeders/data/attributes.php')))
        ->and($first[4])->toBe(count(CatalogSeeder::BRANDS));
});

it('resolves every footer catalog category to an active top-level category', function () {
    $this->seed(CatalogSeeder::class);
    app()->setLocale('en');

    $slugs = collect(CategoryTree::visibleChildren(null))
        ->pluck('slug')
        ->reject(fn (string $slug): bool => $slug === Navigation::SERVICES_SLUG);

    expect($slugs)->toHaveCount(7)
        ->and(collect(Navigation::footerCatalog())->pluck('url')->all())
        ->toBe($slugs->take(Navigation::FOOTER_CATEGORIES)->map(fn (string $slug): string => url("/en/catalog/{$slug}"))->values()->all());

    foreach ($slugs as $slug) {
        $category = Category::query()->where('slug', $slug)->first();

        expect($category)->not->toBeNull("Missing category {$slug}")
            ->and($category->parent_id)->toBeNull()
            ->and($category->is_active)->toBeTrue();
    }
});

it('keeps the tree within two levels and the pivot sort in attribute order', function () {
    $this->seed(CatalogSeeder::class);

    expect(collect(CategoryTree::flattened())->max('depth'))->toBe(2);

    $scanners = Category::query()->where('slug', 'scanners')->firstOrFail();
    $codes = $scanners->attributes()->pluck('code')->all();
    $librarySorts = Attribute::query()->whereIn('code', $codes)->pluck('sort', 'code');

    expect($codes)->toContain('scan_engine', 'interface')
        ->and($librarySorts->sort()->keys()->all())->toBe($codes);
});

it('never makes text attributes filters', function () {
    $this->seed(CatalogSeeder::class);

    $textIds = Attribute::query()->where('type', 'text')->pluck('id');

    expect(Attribute::query()->where('type', 'text')->where('is_filterable', true)->exists())->toBeFalse()
        ->and(DB::table('category_attribute')->whereIn('attribute_id', $textIds)->where('is_filterable', true)->exists())->toBeFalse();
});

it('contains no banned price wording in the seeded copy', function () {
    $this->seed(CatalogSeeder::class);

    $copy = collect()
        ->concat(Category::query()->get()->flatMap(fn (Category $category) => [
            ...$category->getTranslations('name'),
            ...$category->getTranslations('description'),
            ...$category->getTranslations('meta_title'),
            ...$category->getTranslations('meta_description'),
        ]))
        ->concat(Attribute::query()->get()->flatMap(fn (Attribute $attribute) => $attribute->getTranslations('name')))
        ->concat(AttributeOption::query()->get()->flatMap(fn (AttributeOption $option) => $option->getTranslations('value')))
        ->concat(Brand::query()->pluck('name'))
        ->implode("\n");

    expect($copy)->not->toBe('');
    expectNoBannedWording($copy);
});

it('is create-only: admin edits survive a re-run and missing rows are inserted (S1)', function () {
    $this->seed(CatalogSeeder::class);

    $scanners = Category::query()->where('slug', 'scanners')->firstOrFail();
    $scanners->setTranslation('name', 'uz', 'Admin nomi');
    $scanners->forceFill(['sort' => 99, 'is_active' => false])->save();
    $child = Category::query()->where('slug', 'handheld-scanners')->firstOrFail();
    $child->update(['parent_id' => Category::query()->where('slug', 'pos')->value('id')]);
    $engine = Attribute::query()->where('code', 'scan_engine')->firstOrFail();
    $engine->setTranslation('name', 'uz', 'Admin atribut');
    $engine->forceFill(['sort' => 777, 'is_filterable' => false])->save();
    $engine->options()->first()->delete();
    $engine->categories()->updateExistingPivot($scanners->id, ['is_required' => false, 'is_filterable' => false]);
    Brand::query()->where('slug', 'zebra')->update(['name' => 'Zebra Admin', 'is_active' => false]);
    Category::query()->where('slug', 'textile-tapes')->delete();

    $this->seed(CatalogSeeder::class);

    $scanners->refresh();
    $engine->refresh();

    expect($scanners->getTranslation('name', 'uz'))->toBe('Admin nomi')
        ->and($scanners->sort)->toBe(99)
        ->and($scanners->is_active)->toBeFalse()
        ->and($child->fresh()->parent->slug)->toBe('pos')
        ->and($engine->getTranslation('name', 'uz'))->toBe('Admin atribut')
        ->and($engine->sort)->toBe(777)
        ->and($engine->is_filterable)->toBeFalse()
        ->and($engine->categories()->whereKey($scanners->id)->first()->pivot->is_required)->toBeFalse()
        ->and($engine->options()->count())->toBe(3)
        ->and(Brand::query()->where('slug', 'zebra')->first()->only(['name', 'is_active']))->toBe(['name' => 'Zebra Admin', 'is_active' => false])
        ->and(Category::query()->where('slug', 'textile-tapes')->exists())->toBeTrue();
});
