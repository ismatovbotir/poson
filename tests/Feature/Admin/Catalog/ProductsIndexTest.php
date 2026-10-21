<?php

use App\Enums\Role;
use App\Enums\StockStatus;
use App\Livewire\Admin\Products\Index;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDocument;
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
    $active = Product::factory()->create(['sku' => 'ACT-1']);
    $draft = Product::factory()->inactive()->create(['sku' => 'DRF-1']);
    $incomplete = Product::factory()->inactive()->create(['sku' => 'INC-1', 'has_missing_specs' => true]);
    $trashed = Product::factory()->create(['sku' => 'TRS-1']);
    $trashed->delete();

    $component = Livewire::actingAs(staffUser())->test(Index::class);

    expect($component->get('tabCounts'))->toBe(['active' => 1, 'drafts' => 2, 'incomplete' => 1, 'trash' => 1]);

    $component->assertSee('ACT-1')->assertDontSee('DRF-1')->assertDontSee('TRS-1')
        ->call('setTab', 'drafts')->assertSee('DRF-1')->assertSee('INC-1')->assertDontSee('ACT-1')
        ->call('setTab', 'incomplete')->assertSee('INC-1')->assertDontSee('DRF-1')
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
