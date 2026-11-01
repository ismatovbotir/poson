<?php

use App\Actions\Catalog\ApplyCategoryIcons;
use App\Livewire\Admin\Categories\Form;
use App\Models\Category;
use Database\Seeders\CatalogSeeder;

function seededCategoryIcons(): array
{
    return Category::query()->pluck('icon', 'slug')->all();
}

it('has a sprite symbol for every allow-listed icon and safe key names', function () {
    $sprite = file_get_contents(resource_path('views/components/catalog/category-icon-sprite.blade.php')).file_get_contents(resource_path('views/components/site/icon-sprite.blade.php'));

    foreach (Category::ICONS as $key) {
        expect($key)->toMatch('/^[a-z0-9-]+$/')
            ->and($sprite)->toContain('id="i-'.$key.'"');
    }

    expect(Category::ICONS)->toHaveCount(count(array_unique(Category::ICONS)));
});

it('keeps the category sprite symbols static and uncoloured', function () {
    $sprite = file_get_contents(resource_path('views/components/catalog/category-icon-sprite.blade.php')).file_get_contents(resource_path('views/components/site/icon-sprite.blade.php'));

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

it('has uz, ru and en labels for every allow-listed icon', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach (Category::ICONS as $key) {
            $label = __('admin.categories.icons.'.$key, [], $locale);

            expect($label)->not->toBe('admin.categories.icons.'.$key)
                ->and($label)->not->toBe($key)
                ->and($label)->not->toBeEmpty();
        }
    }
});

it('keeps the allow-list alphabetical and uses the right Uzbek marks', function () {
    $catKeys = array_values(array_filter(Category::ICONS, fn (string $key) => str_starts_with($key, 'cat-')));
    $sorted = $catKeys;
    sort($sorted);

    expect($catKeys)->toBe($sorted);

    foreach (Category::ICONS as $key) {
        expect(__('admin.categories.icons.'.$key, [], 'uz'))->not->toContain("'");
    }
});

it('rejects an icon key that is not allow-listed in the category form', function () {
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.name.uz', 'Test')
        ->set('form.icon', 'cat-not-in-list')
        ->call('save')
        ->assertHasErrors(['form.icon']);
});

it('keeps every allow-listed icon symbol in the rendered site sprite', function () {
    $html = Blade::render('<x-site.icon-sprite />');

    foreach (Category::ICONS as $key) {
        expect(substr_count($html, 'id="i-'.$key.'"'))->toBe(1);
    }
});

it('renders the icon picker with the current icon and each category symbol once on the admin form', function () {
    $category = Category::factory()->create(['icon' => 'cat-laptop']);

    $html = Livewire::actingAs(staffUser())
        ->test(Form::class, ['category' => $category])
        ->assertSee(__('admin.icon_picker.search'))
        ->assertSet('form.icon', 'cat-laptop')
        ->html();

    expect($html)->toContain('aria-label="'.__('admin.categories.icons.cat-laptop').'"')
        ->toContain('x-data');

    foreach (array_filter(Category::ICONS, fn (string $key) => str_starts_with($key, 'cat-')) as $key) {
        expect(substr_count($html, 'id="i-'.$key.'"'))->toBe(1);
    }
});

it('saves a chosen icon and null for no icon', function () {
    $category = Category::factory()->create(['icon' => null]);
    $component = Livewire::actingAs(staffUser())
        ->test(Form::class, ['category' => $category]);

    $component->set('form.icon', 'cat-server')->call('save')->assertHasNoErrors();
    expect($category->fresh()->icon)->toBe('cat-server');

    $component->set('form.icon', '')->call('save')->assertHasNoErrors();
    expect($category->fresh()->icon)->toBeNull();
});

it('has the legacy icons outside the category partial in the admin sprite', function () {
    $admin = file_get_contents(resource_path('views/components/admin/icon-sprite.blade.php'));

    foreach (['wrench', 'shield', 'truck'] as $key) {
        expect(Category::ICONS)->toContain($key)
            ->and($admin)->toContain('id="i-'.$key.'"');
    }
});
