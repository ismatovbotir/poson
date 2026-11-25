<?php

use App\Enums\Role;
use App\Enums\StockStatus;
use App\Livewire\Admin\Products\CategoryChooser;
use App\Livewire\Admin\Products\Index;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders the list for every staff role', function (Role $role) {
    Product::factory()->create(['name' => ['uz' => 'Zebra DS2208 skaner']]);

    $this->actingAs(staffUser($role))
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class)
        ->assertSee('Zebra DS2208 skaner');
})->with([...catalogWriterRoles(), ...catalogViewOnlyRoles()]);

it('splits products into active, drafts, specs incomplete and trash', function () {
    $active = Product::factory()->withIkpu()->create(['sku' => 'ACT-1']);
    $draft = Product::factory()->inactive()->create(['sku' => 'DRF-1']);
    $incomplete = Product::factory()->inactive()->create(['sku' => 'INC-1', 'has_missing_specs' => true]);
    $trashed = Product::factory()->create(['sku' => 'TRS-1']);
    $trashed->delete();

    $component = Livewire::actingAs(staffUser())->test(Index::class);

    expect($component->get('tabCounts'))->toBe(['active' => 1, 'drafts' => 2, 'incomplete' => 1, 'no_ikpu' => 2, 'trash' => 1]);

    $component->assertSee('ACT-1')->assertDontSee('DRF-1')->assertDontSee('TRS-1')
        ->call('setTab', 'drafts')->assertSee('DRF-1')->assertSee('INC-1')->assertDontSee('ACT-1')
        ->call('setTab', 'incomplete')->assertSee('INC-1')->assertDontSee('DRF-1')
        ->call('setTab', 'no_ikpu')->assertSee('DRF-1')->assertSee('INC-1')->assertDontSee('ACT-1')
        ->call('setTab', 'trash')->assertSee('TRS-1')->assertDontSee('ACT-1');
});

it('searches search_text by SKU, name in any locale and brand, ignoring apostrophes', function () {
    $brand = Brand::factory()->create(['name' => 'Honeywell']);
    Product::factory()->for($brand)->create(['sku' => 'HON-1470G', 'name' => ['uz' => 'Simli skaner', 'ru' => 'Проводной сканер']]);
    Product::factory()->create(['sku' => 'TSC-TE200', 'name' => ['uz' => 'Oʻrta printer']]);

    $component = Livewire::actingAs(staffUser())->test(Index::class);

    $component->set('search', 'hon-1470')->assertSee('HON-1470G')->assertDontSee('TSC-TE200');
    $component->set('search', 'ПРОВОДНОЙ')->assertSee('HON-1470G')->assertDontSee('TSC-TE200');
    $component->set('search', 'honeywell')->assertSee('HON-1470G');
    $component->set('search', "o'rta")->assertSee('TSC-TE200')->assertDontSee('HON-1470G');
});

it('filters by category with its subcategories, brand, type and stock', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    $elsewhere = Category::factory()->create();
    $brand = Brand::factory()->create();

    Product::factory()->inCategory($child)->for($brand)->create(['sku' => 'IN-CHILD']);
    Product::factory()->inCategory($elsewhere)->service()->create(['sku' => 'SRV-1', 'stock_status' => StockStatus::OnOrder]);

    $component = Livewire::actingAs(staffUser())->test(Index::class);

    $component->set('category', (string) $root->id)->assertSee('IN-CHILD')->assertDontSee('SRV-1');
    $component->set('category', '')->set('brand', (string) $brand->id)->assertSee('IN-CHILD')->assertDontSee('SRV-1');
    $component->set('brand', '')->set('type', 'service')->assertSee('SRV-1')->assertDontSee('IN-CHILD');
    $component->set('type', '')->set('stock', 'on_order')->assertSee('SRV-1')->assertDontSee('IN-CHILD');
    $component->call('clearFilters')->assertSee('SRV-1')->assertSee('IN-CHILD');
});

it('sorts by whitelisted keys only', function () {
    Product::factory()->create(['sku' => 'CHEAP-NOT', 'price' => 100_00]);
    Product::factory()->create(['sku' => 'BIG-ONE', 'price' => 900_000_00]);

    $component = Livewire::actingAs(staffUser())->test(Index::class);

    $component->set('sort', 'price_desc')->assertSeeInOrder(['BIG-ONE', 'CHEAP-NOT']);
    $component->set('sort', 'price_asc')->assertSeeInOrder(['CHEAP-NOT', 'BIG-ONE']);
    $component->set('sort', 'id; drop table products')->assertOk()->assertSee('BIG-ONE');
});

it('publishes drafts in bulk but keeps incomplete ones as drafts', function () {
    $category = Category::factory()->create();
    $category->attributes()->attach(Attribute::factory()->create(), ['sort' => 1, 'is_required' => true]);
    $complete = Product::factory()->inactive()->create();
    $incomplete = Product::factory()->inactive()->inCategory($category)->create();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Index::class)
        ->set('selected', [$complete->id, $incomplete->id])
        ->call('bulkActivate')
        ->assertDispatched('toast')
        ->assertSet('selected', []);

    expect($complete->fresh()->is_active)->toBeTrue()
        ->and($incomplete->fresh())->is_active->toBeFalse()->has_missing_specs->toBeTrue();
});

it('unpublishes and moves products to the trash in bulk', function () {
    [$a, $b] = Product::factory()->count(2)->create();

    $component = Livewire::actingAs(staffUser())->test(Index::class);
    $component->set('selected', [$a->id, $b->id])->call('bulkDeactivate');

    expect($a->fresh()->is_active)->toBeFalse()->and($b->fresh()->is_active)->toBeFalse();

    $component->set('selected', [$a->id])->call('bulkDelete');

    expect($a->fresh()->trashed())->toBeTrue()->and($b->fresh()->trashed())->toBeFalse();
});

it('restores from the trash and lets only admins delete permanently', function () {
    Storage::fake('local');
    $product = Product::factory()->create();
    $path = UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')->store('product-documents/'.$product->id, 'local');
    ProductDocument::factory()->for($product)->create(['path' => $path, 'url' => null]);
    $kept = Product::factory()->create();
    $product->delete();
    $kept->delete();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class)
        ->set('tab', 'trash')
        ->assertDontSee(__('admin.products.force_delete').':')
        ->call('forceDelete', $product->id)
        ->assertForbidden();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class)->call('restore', $kept->id);
    expect($kept->fresh()->trashed())->toBeFalse();

    Livewire::actingAs(staffUser())->test(Index::class)->set('tab', 'trash')->call('forceDelete', $product->id)->assertDispatched('toast');

    expect(Product::withTrashed()->find($product->id))->toBeNull()
        ->and(ProductDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows a service engineer edit actions only on software and service products', function () {
    $hardware = Product::factory()->create(['sku' => 'HW-1']);
    $service = Product::factory()->service()->create(['sku' => 'SRV-2']);
    $user = staffUser(Role::ServiceEngineer);

    $html = Livewire::actingAs($user)->test(Index::class)->html();

    expect(substr_count($html, 'data-edit'))->toBe(2) // table + mobile card of the service product
        ->and(substr_count($html, 'data-delete'))->toBe(2) // table + mobile card of the service product
        ->and($html)->toContain(__('admin.common.edit').': '.$service->name)
        ->and($html)->not->toContain(__('admin.common.edit').': '.$hardware->name);

    Livewire::actingAs($user)->test(Index::class)->call('delete', $hardware->id)->assertForbidden();
    Livewire::actingAs($user)->test(Index::class)->set('selected', [$hardware->id, $service->id])->call('bulkDeactivate')->assertForbidden();
    Livewire::actingAs($user)->test(Index::class)->call('delete', $service->id)->assertDispatched('toast');

    expect($hardware->fresh()->is_active)->toBeTrue()->and($service->fresh()->trashed())->toBeTrue();
});

it('forbids writes on the list for the sales manager', function () {
    $product = Product::factory()->service()->create();
    $user = staffUser(Role::SalesManager);

    Livewire::actingAs($user)->test(Index::class)->call('delete', $product->id)->assertForbidden();
    Livewire::actingAs($user)->test(Index::class)->set('selected', [$product->id])->call('bulkDelete')->assertForbidden();

    expect($product->fresh()->trashed())->toBeFalse();
});

it('lists products with a constant number of queries (no N+1)', function () {
    $user = staffUser(Role::ServiceEngineer);
    $count = function () use ($user): int {
        Livewire::actingAs($user)->test(Index::class); // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test(Index::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Product::factory()->count(2)->create();
    $few = $count();

    Product::factory()->count(10)->create();
    $many = $count();

    expect($many)->toBe($few);
});

it('adds no per-row queries for the copy list', function (Role $role) {
    $user = staffUser($role);
    $count = function () use ($user): int {
        Livewire::actingAs($user)->test(Index::class); // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test(Index::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Product::factory()->count(5)->create();
    $few = $count();

    Product::factory()->count(20)->create();
    $many = $count();

    expect($many)->toBe($few);
})->with([Role::Admin, Role::ServiceEngineer]);

describe('category chooser triggers', function () {
    it('shows the triggers and the modal only to roles that can create (CP-1)', function (Role $role, bool $sees) {
        $component = Livewire::actingAs(staffUser($role))->test(Index::class);

        if ($sees) {
            $component->assertSeeHtml('open-category-chooser')->assertSeeLivewire(CategoryChooser::class);
        } else {
            $component->assertDontSeeHtml('open-category-chooser')->assertDontSeeLivewire(CategoryChooser::class)->assertDontSeeHtml('category-chooser-title');
        }
    })->with([
        'sales manager' => [Role::SalesManager, false],
        'content manager' => [Role::ContentManager, true],
        'service engineer' => [Role::ServiceEngineer, true],
    ]);

    it('also opens the chooser from the empty-state button', function () {
        $html = Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class)->html();

        expect(substr_count($html, "wire:click=\"\$dispatch('open-category-chooser')\""))->toBe(2);
    });

    it('does not load the category tree for the modal while closed (CP-8)', function () {
        Category::factory()->create();
        $component = Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class);
        CategoryTree::flush();

        DB::enableQueryLog();
        $component->call('$refresh');
        $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from `categories`'));
        DB::disableQueryLog();

        expect($queries)->toBeEmpty();
    });
});

describe('generated diagram thumbnails', function () {
    function indexDiagramLabel(string $name = 'Secret Label Name'): Product
    {
        $category = Category::query()->where('slug', 'thermal-labels')->first() ?? Category::factory()->create(['slug' => 'thermal-labels']);
        $product = Product::factory()->perLabel(12)->inCategory($category)
            ->withRollTypes([RollType::query()->where('labels_per_roll', 500)->first() ?? RollType::factory()->size(500, '40.0')->create()])
            ->create(['name' => ['uz' => $name, 'ru' => $name, 'en' => $name]]);

        foreach (['label_width' => 100, 'label_height' => 150] as $code => $value) {
            $attribute = Attribute::query()->where('code', $code)->first() ?? Attribute::factory()->number('mm')->create(['code' => $code]);
            $row = ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $attribute->id]);
            DB::table('product_attribute_values')->where('id', $row->id)->update(['value_number' => $value, 'value_text' => null]);
        }

        return $product;
    }

    function indexDiagramRibbon(): Product
    {
        $product = Product::factory()->consumable()->inCategory(Category::query()->where('slug', 'ribbons')->firstOrFail())->create();

        foreach (['ribbon_width' => 110, 'ribbon_length' => 300] as $code => $value) {
            $attribute = Attribute::query()->where('code', $code)->firstOrFail();
            $row = ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $attribute->id]);
            DB::table('product_attribute_values')->where('id', $row->id)->update(['value_number' => $value, 'value_text' => null]);
        }

        return $product;
    }

    beforeEach(function () {
        openSearchIndexing();
        $this->seed(CatalogSeeder::class);
    });

    it('shows the label diagram for a product without a photo, with no DB text in the title', function () {
        indexDiagramLabel('Secret Label Name');

        $html = Livewire::actingAs(staffUser())->test(Index::class)->html();

        expect($html)->toContain('data-label-diagram')->not->toContain('data-product-thumb');
        preg_match('#<svg[^>]*data-label-diagram.*?</svg>#s', $html, $svg);
        preg_match('#<title[^>]*>(.*?)</title>#s', $svg[0], $title);
        expect($svg[0])->toContain('aria-hidden="true"')
            ->and($title[1])->not->toContain('Secret Label Name');
    });

    it('keeps the photo thumbnail and the placeholder when there is no diagram', function () {
        $withPhoto = indexDiagramLabel('Photo Label');
        $withPhoto->addMedia(UploadedFile::fake()->image('p.jpg', 200, 200))->toMediaCollection('images');
        Product::factory()->create(['name' => ['uz' => 'Plain Product']]);

        $html = Livewire::actingAs(staffUser())->test(Index::class)->html();

        expect($html)->toContain('data-product-thumb')->not->toContain('data-label-diagram')->not->toContain('data-roll-diagram');
        expect(substr_count($html, 'data-icon') + substr_count($html, '<svg'))->toBeGreaterThan(0);
    });

    it('shows the roll diagram in the trash tab', function () {
        indexDiagramRibbon()->delete();

        Livewire::actingAs(staffUser())->test(Index::class, ['tab' => 'trash'])->assertSeeHtml('data-roll-diagram');
    });

    it('gives two label diagrams unique title ids', function () {
        indexDiagramLabel('One');
        indexDiagramLabel('Two');

        $html = Livewire::actingAs(staffUser())->test(Index::class)->html();
        preg_match_all('#aria-labelledby="([^"]+)"#', $html, $refs);
        $refs = array_values(array_filter($refs[1], fn (string $id) => str_starts_with($id, 'label-diagram-')));

        expect($refs)->not->toBeEmpty()->and(array_unique($refs))->toHaveCount(count($refs));
        foreach ($refs as $id) {
            expect($html)->toContain('<title id="'.$id.'"');
        }
    });

    it('loads diagram specs with a constant number of queries', function () {
        $user = staffUser();
        $count = function () use ($user): int {
            Livewire::actingAs($user)->test(Index::class); // warm caches
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($user)->test(Index::class);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        indexDiagramLabel();
        $one = $count();

        foreach (range(1, 9) as $i) {
            indexDiagramLabel();
        }
        expect($count())->toBe($one);
    });
});
