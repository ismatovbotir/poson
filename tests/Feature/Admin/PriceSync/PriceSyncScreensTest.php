<?php

use App\Enums\Role;
use App\Livewire\Admin\PriceSync\Index;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Support\Money;
use Livewire\Livewire;

$roles = [
    'admin' => [Role::Admin, 200],
    'content manager' => [Role::ContentManager, 403],
    'sales manager' => [Role::SalesManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
];

it('opens the log for admins only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.price-sync.index'))->assertStatus($status);
})->with($roles);

it('opens a batch for admins only', function (Role $role, int $status) {
    $log = PriceSyncLog::factory()->create();

    $this->actingAs(staffUser($role))->get(route('admin.price-sync.show', $log))->assertStatus($status);
})->with($roles);

it('replaces the price import item in the catalog menu', function () {
    $this->actingAs(staffUser())->get(route('admin.dashboard'))
        ->assertSee(route('admin.price-sync.index'), false)
        ->assertSee('Narxlar sinxronizatsiyasi');
});

it('lists batches and filters them by token, status and date', function () {
    $this->actingAs(staffUser());
    $office = ApiToken::factory()->create(['name' => 'Office']);
    $store = ApiToken::factory()->create(['name' => 'Store']);

    $ok = PriceSyncLog::factory()->for($office)->create(['token_name' => 'Office']);
    $partial = PriceSyncLog::factory()->for($store)->withUnknown(['X1'])->create(['token_name' => 'Store']);
    $rejected = PriceSyncLog::factory()->for($store)->rejected('items_empty')->create(['token_name' => 'Store', 'created_at' => now()->subDays(10)]);

    Livewire::test(Index::class)
        ->assertSeeHtml('data-sync="'.$ok->id.'"')
        ->assertSeeHtml('data-sync="'.$rejected->id.'"')
        ->assertSee('items_empty')
        ->set('token', (string) $store->id)
        ->assertDontSeeHtml('data-sync="'.$ok->id.'"')
        ->assertSeeHtml('data-sync="'.$partial->id.'"')
        ->set('status', 'rejected')
        ->assertDontSeeHtml('data-sync="'.$partial->id.'"')
        ->assertSeeHtml('data-sync="'.$rejected->id.'"')
        ->call('clearFilters')
        ->set('from', now('Asia/Tashkent')->subDay()->format('Y-m-d'))
        ->assertDontSeeHtml('data-sync="'.$rejected->id.'"')
        ->assertSeeHtml('data-sync="'.$ok->id.'"')
        ->set('from', '')
        ->set('to', now('Asia/Tashkent')->subDays(5)->format('Y-m-d'))
        ->assertSeeHtml('data-sync="'.$rejected->id.'"')
        ->assertDontSeeHtml('data-sync="'.$ok->id.'"')
        ->set('to', 'garbage')
        ->assertSeeHtml('data-sync="'.$ok->id.'"');
});

it('shows the empty state', function () {
    $this->actingAs(staffUser());

    Livewire::test(Index::class)->assertSee(__('api.sync.empty_title'));
});

it('shows a batch with the per-item table and the unknown codes', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create(['sku' => 'ZD421', 'price' => 100_00]);
    ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create(['name' => '1C office']);

    postPrices(['items' => [priceItem('ZD421', 1_250_000, 1_200_000, null), priceItem('NOPE-7', 1), priceItem('BAD', 0)]], $plain)->assertOk();
    $log = PriceSyncLog::query()->sole();

    $html = $this->get(route('admin.price-sync.show', $log))->assertOk()->getContent();

    expect($html)
        ->toContain('1C office')
        ->toContain('data-unknown-codes')
        ->toContain('NOPE-7')
        ->toContain('retail_out_of_range')
        ->toContain(e(__('api.errors.retail_out_of_range')))
        ->toContain(route('admin.products.edit', $product))
        ->toContain(Money::format(1_250_000_00))
        ->toContain('data-item-status="updated"')
        ->toContain('data-item-status="unknown"')
        ->toContain('data-item-status="invalid"');
});

it('shows a rejected batch with its error code', function () {
    $this->actingAs(staffUser());
    $log = PriceSyncLog::factory()->rejected('too_many_items')->create();

    $this->get(route('admin.price-sync.show', $log))->assertOk()->assertSee('data-sync-error', false)->assertSee('too_many_items')->assertSee(__('api.errors.too_many_items'));
});
