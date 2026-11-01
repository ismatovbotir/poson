<?php

use App\Actions\Catalog\ApplyCategoryIcons;
use App\Models\Category;
use Database\Seeders\CatalogSeeder;

function seededCategoryIcons(): array
{
    return Category::query()->pluck('icon', 'slug')->all();
}

it('has a sprite symbol for every allow-listed icon and safe key names', function () {
    $sprite = file_get_contents(resource_path('views/components/site/icon-sprite.blade.php'));

    foreach (Category::ICONS as $key) {
        expect($key)->toMatch('/^[a-z0-9-]+$/')
            ->and($sprite)->toContain('id="i-'.$key.'"');
    }

    expect(Category::ICONS)->toHaveCount(count(array_unique(Category::ICONS)));
});

it('keeps the category sprite symbols static and uncoloured', function () {
    $sprite = file_get_contents(resource_path('views/components/site/icon-sprite.blade.php'));

    preg_match_all('/<symbol id="i-cat-[^"]+".*?<\/symbol>/s', $sprite, $symbols);

    expect($symbols[0])->not->toBeEmpty();

    foreach ($symbols[0] as $symbol) {
        expect(preg_match('/<(script|foreignObject|image|style|use)|\son\w+=|href=|url\(|style=|fill=|stroke=|\{\{|@/i', $symbol))->toBe(0);
    }
});

it('uses only allow-listed icons in the category seed data', function () {
    $walk = function (array $nodes) use (&$walk): void {
        foreach ($nodes as $node) {
            expect($node['icon'])->toBeIn(Category::ICONS);
            $walk($node['children'] ?? []);
        }
    };

    $walk(require database_path('seeders/data/categories.php'));
});

it('maps only allow-listed icons and existing seed slugs', function () {
    foreach (ApplyCategoryIcons::MAP as [$old, $new]) {
        expect($new)->toBeIn(Category::ICONS);
    }

    $this->seed(CatalogSeeder::class);

    expect(array_diff(array_keys(ApplyCategoryIcons::MAP), Category::query()->pluck('slug')->all()))->toBe([]);
});

it('replaces empty and old default icons, keeps admin choices, and is idempotent', function () {
    $this->seed(CatalogSeeder::class);

    // State of the production DB before this change.
    foreach (ApplyCategoryIcons::MAP as $slug => [$old]) {
        Category::query()->where('slug', $slug)->toBase()->update(['icon' => $old]);
    }
    Category::query()->where('slug', 'scanners')->toBase()->update(['icon' => 'truck']);
    Category::query()->where('slug', 'ribbons')->toBase()->update(['icon' => 'doc']);

    $updatedAt = Category::query()->where('slug', 'handheld-scanners')->value('updated_at');

    $changed = app(ApplyCategoryIcons::class)->handle();
    $after = seededCategoryIcons();

    expect($changed)->toBe(count(ApplyCategoryIcons::MAP) - 2)
        ->and($after['handheld-scanners'])->toBe('cat-handheld-scanner')
        ->and($after['software'])->toBe('cat-pos-software')
        ->and($after['label-printers'])->toBe('cat-desktop-label-printer')
        ->and($after['scanners'])->toBe('truck')
        ->and($after['ribbons'])->toBe('doc')
        ->and(Category::query()->where('slug', 'handheld-scanners')->value('updated_at'))->toEqual($updatedAt);

    expect(app(ApplyCategoryIcons::class)->handle())->toBe(0)
        ->and(seededCategoryIcons())->toBe($after);
});
