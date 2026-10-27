<?php

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\UnlinkProductPriceSync;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Products\Form;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Models\SettingsAudit;
use App\Support\Catalog\ProductAttributeResolver;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Save the product through SaveProduct with only the prices changed.
 */
function saveWithPrices(Product $product, array $prices): Product
{
    $data = [
        'type' => $product->type,
        'category_id' => $product->category_id,
        'brand_id' => $product->brand_id,
        'sku' => $product->sku,
        'model' => $product->model,
        'slug' => $product->slug,
        'name' => $product->getTranslations('name'),
        'short_description' => [],
        'description' => [],
        'meta_title' => [],
        'meta_description' => [],
        'unit' => $product->unit,
        'min_qty' => $product->min_qty,
        'sale_mode' => $product->sale_mode,
        'stock_status' => $product->stock_status,
        'warranty_months' => $product->warranty_months,
        'badge' => $product->badge,
        'is_active' => $product->is_active,
        'is_featured' => $product->is_featured,
        ...$prices,
    ];

    return app(SaveProduct::class)->handle($product, $data, []);
}

it('ignores incoming prices of a synced product in SaveProduct', function () {
    $product = Product::factory()->priceSynced()->create(['price' => 500_00, 'price_dealer' => 450_00, 'price_wholesale' => null]);

    saveWithPrices($product, ['price' => null, 'price_dealer' => 1_00, 'price_wholesale' => 2_00, 'model' => 'NEW-MODEL']);

    expect($product->fresh())
        ->price->toBe(500_00)
        ->price_dealer->toBe(450_00)
        ->price_wholesale->toBeNull()
        ->model->toBe('NEW-MODEL');
});

it('still saves prices of a product that is not synced', function () {
    $product = Product::factory()->create(['price' => 500_00]);

    saveWithPrices($product, ['price' => 600_00, 'price_dealer' => null, 'price_wholesale' => null]);

    expect($product->fresh()->price)->toBe(600_00);
});

it('shows synced prices read-only with the date and a link to the batch', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create(['sku' => 'SYNC-1', 'price' => 100_00]);
    ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create();
    postPrices(['items' => [priceItem('SYNC-1', 200)]], $plain)->assertOk();
    $log = PriceSyncLog::query()->sole();

    Livewire::test(Form::class, ['product' => $product->fresh()])
        ->assertSeeHtml('data-price-synced')
        ->assertSeeHtml(route('admin.price-sync.show', $log))
        ->assertSeeHtml('data-unlink-price-sync')
        ->assertSeeHtml('wire:model="form.price"')
        ->assertSeeHtml('disabled="disabled"');
});

it('ignores a forged Livewire price update on a synced product', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->priceSynced()->create(['price' => 500_00, 'price_dealer' => null, 'price_wholesale' => null]);

    Livewire::test(Form::class, ['product' => $product])
        ->set('form.price', '1')
        ->set('form.price_dealer', '2')
        ->set('form.model', 'EDITED')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh())
        ->price->toBe(500_00)
        ->price_dealer->toBeNull()
        ->model->toBe('EDITED');
});

it('hides the notice and the unlink button for unsynced products and non-admins', function () {
    $this->actingAs(staffUser(Role::ContentManager));
    $synced = Product::factory()->priceSynced()->create();

    Livewire::test(Form::class, ['product' => $synced])
        ->assertSeeHtml('data-price-synced')
        ->assertDontSeeHtml('data-unlink-price-sync')
        ->assertDontSeeHtml('data-price-sync-log-link')
        ->call('unlinkPriceSync')
        ->assertForbidden();

    expect($synced->fresh()->price_synced_at)->not->toBeNull();

    $this->actingAs(staffUser());
    Livewire::test(Form::class, ['product' => Product::factory()->create()])->assertDontSeeHtml('data-price-synced');
});

it('unlinks a product from the API after a password confirmation, audited', function () {
    $this->actingAs($admin = staffUser());
    $product = Product::factory()->priceSynced()->create(['price' => 500_00]);

    Livewire::test(Form::class, ['product' => $product])
        ->call('unlinkPriceSync')
        ->assertRedirect(route('admin.confirm-password'));

    expect($product->fresh()->price_synced_at)->not->toBeNull();

    confirmAdminPassword();

    Livewire::test(Form::class, ['product' => $product])
        ->call('unlinkPriceSync')
        ->assertDispatched('toast')
        ->assertDontSeeHtml('data-price-synced')
        ->set('form.price', '700')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh())->price_synced_at->toBeNull()->price->toBe(700_00)
        ->and(SettingsAudit::query()->sole())
        ->area->toBe(SettingsArea::PriceSync)
        ->key->toBe('products.'.$product->id)
        ->action->toBe(AuditAction::Unlinked)
        ->user_id->toBe($admin->id);
});

it('unlinks only once', function () {
    $product = Product::factory()->priceSynced()->create();
    $unlink = app(UnlinkProductPriceSync::class);

    expect($unlink->handle($product, null, null))->toBeTrue()
        ->and($unlink->handle($product, null, null))->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(1);
});

it('links the product again on the next batch with its SKU', function () {
    $product = Product::factory()->create(['sku' => 'RELINK']);
    ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create();

    postPrices(['items' => [priceItem('RELINK', 10)]], $plain)->assertOk();
    app(UnlinkProductPriceSync::class)->handle($product, null, null);
    expect($product->fresh()->price_synced_at)->toBeNull();

    postPrices(['items' => [priceItem('RELINK', 10)]], $plain)->assertOk();
    expect($product->fresh()->price_synced_at)->not->toBeNull();

    // The form still loads (resolver untouched).
    expect(app(ProductAttributeResolver::class)->forProduct($product->fresh()))->toBeArray();
});

it('refuses SKU changes while the product is synced, in SaveProduct and in the form', function () {
    $product = Product::factory()->priceSynced()->create(['sku' => 'LOCKED-1']);

    expect(fn () => saveWithPrices($product, ['sku' => 'LOCKED-2', 'price' => $product->price, 'price_dealer' => null, 'price_wholesale' => null]))
        ->toThrow(ValidationException::class, __('api.product.sku_locked'));

    // The same SKU (any case / spaces) is fine.
    saveWithPrices($product->fresh(), ['sku' => ' locked-1 ', 'price' => null, 'price_dealer' => null, 'price_wholesale' => null]);

    $this->actingAs(staffUser());

    Livewire::test(Form::class, ['product' => $product->fresh()])
        ->assertSeeHtml(__('api.product.sku_locked'))
        ->set('form.sku', 'LOCKED-2')
        ->call('save')
        ->assertHasErrors(['form.sku' => __('api.product.sku_locked')]);

    expect($product->fresh()->sku)->toBe('LOCKED-1');
});

it('allows SKU changes again after unlinking', function () {
    $product = Product::factory()->priceSynced()->create(['sku' => 'FREE-1']);
    app(UnlinkProductPriceSync::class)->handle($product, null, null);

    saveWithPrices($product->fresh(), ['sku' => 'FREE-2', 'price' => 1_00, 'price_dealer' => null, 'price_wholesale' => null]);

    expect($product->fresh()->sku)->toBe('FREE-2');
});

it('keeps prices from a batch that arrived after the form was loaded', function () {
    $product = Product::factory()->create(['sku' => 'RACE', 'price' => 100_00, 'price_dealer' => null, 'price_wholesale' => null]);
    $stale = Product::query()->find($product->id);

    // 1C syncs while the admin still has the old form open.
    ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create();
    postPrices(['items' => [priceItem('RACE', 300, 250, null)]], $plain)->assertOk();

    saveWithPrices($stale, ['price' => 999_00, 'price_dealer' => null, 'price_wholesale' => null, 'model' => 'AFTER']);

    expect($product->fresh())
        ->price->toBe(300_00)
        ->price_dealer->toBe(250_00)
        ->model->toBe('AFTER');
});

it('clears the batch link on unlink', function () {
    $product = Product::factory()->create(['sku' => 'CLR']);
    ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create();
    postPrices(['items' => [priceItem('CLR', 10)]], $plain)->assertOk();

    expect($product->fresh()->price_sync_log_id)->not->toBeNull();

    app(UnlinkProductPriceSync::class)->handle($product, null, null);

    expect($product->fresh())->price_sync_log_id->toBeNull()->price_synced_at->toBeNull();
});
