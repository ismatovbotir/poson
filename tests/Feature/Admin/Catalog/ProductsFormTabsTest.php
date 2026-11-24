<?php

use App\Enums\Role;
use App\Livewire\Admin\Products\Documents;
use App\Livewire\Admin\Products\Form;
use App\Livewire\Admin\Products\Images;
use App\Livewire\Admin\Products\Relations;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use Livewire\Livewire;

/**
 * The product form is tabbed in Alpine only (no server property): main, pricing,
 * specs, images, documents, faq, seo. Every panel stays in the DOM (x-show).
 */
const PRODUCT_TAB_KEYS = ['main', 'pricing', 'specs', 'images', 'documents', 'faq', 'seo'];

it('has no server-side tab property to tamper with (a)', function () {
    $this->actingAs(staffUser());

    $component = Livewire::withQueryParams(['tab' => '<script>'])->test(Form::class, ['product' => Product::factory()->create()]);

    expect(property_exists($component->instance(), 'tab'))->toBeFalse();

    expect(fn () => $component->set('tab', '../x'))->toThrow(Exception::class);
});

it('renders the seven tabs and keeps every panel in the HTML (e)', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create();

    $html = $this->get(route('admin.products.edit', $product))->assertOk()
        ->assertSeeLivewire(Images::class)
        ->assertSeeLivewire(Documents::class)
        ->assertSeeLivewire(Relations::class)
        ->assertSee('data-ai-fill', false)
        ->getContent();

    foreach (PRODUCT_TAB_KEYS as $key) {
        expect($html)->toContain('id="product-tab-'.$key.'"')
            ->and($html)->toContain("x-show=\"tab === '{$key}'\"");
    }

    // Publish and classification now live in the Main tab, the form is full width.
    expect($html)->not->toContain('lg:grid-cols-3');
});

it('validates every tab on save and shows both errors, then jumps to the first tab with errors (b)', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create();

    Livewire::test(Form::class, ['product' => $product])
        ->set('form.name.uz', '')
        ->set('form.meta_title.uz', str_repeat('x', 300))
        ->call('save')
        ->assertHasErrors(['form.name.uz', 'form.meta_title.uz'])
        ->assertDispatched('product-tab-errors', tab: 'main')
        ->assertSeeHtml('data-tab-errors');
});

it('jumps to seo when only an SEO field is invalid (b)', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create();

    Livewire::test(Form::class, ['product' => $product])
        ->set('form.meta_title.uz', str_repeat('x', 300))
        ->call('save')
        ->assertHasErrors(['form.meta_title.uz'])
        ->assertDispatched('product-tab-errors', tab: 'seo');
});

it('persists price values from the pricing tab and keeps FAQ items on save (b)', function () {
    $this->actingAs(staffUser());
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'brand_id' => $brand->id, 'price' => 100_00]);
    $faq = FaqItem::factory()->create(['faqable_type' => $product->getMorphClass(), 'faqable_id' => $product->id]);

    Livewire::test(Form::class, ['product' => $product])
        ->set('form.price', '2 500 000')
        ->set('form.price_dealer', '2 000 000')
        ->set('form.min_qty', '3')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh())
        ->price->toBe(2_500_000_00)
        ->price_dealer->toBe(2_000_000_00)
        ->min_qty->toBe(3)
        ->and($faq->fresh())->not->toBeNull();
});

it('keeps the price-sync lock on the tabbed form (d)', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->priceSynced()->create(['price' => 500_00, 'price_dealer' => null, 'price_wholesale' => null]);

    Livewire::test(Form::class, ['product' => $product])
        ->assertSeeHtml('data-price-synced')
        ->assertSeeHtml('data-unlink-price-sync')
        ->set('form.price', '1')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->price)->toBe(500_00)
        ->and($product->fresh()->price_synced_at)->not->toBeNull();
});

it('maps validation keys to their tab', function () {
    expect(Form::firstTabWithErrors(['form.meta_description.ru']))->toBe('seo')
        ->and(Form::firstTabWithErrors(['form.price_dealer', 'form.meta_title.uz']))->toBe('pricing')
        ->and(Form::firstTabWithErrors(['form.name.uz', 'form.price']))->toBe('main')
        ->and(Form::firstTabWithErrors(['form.specs']))->toBe('specs')
        ->and(Form::firstTabWithErrors(['unknown']))->toBe('main');
});

it('authorizes the tabbed screen per role (c)', function (Role $role, bool $canEdit) {
    $this->actingAs(staffUser($role));
    $product = Product::factory()->create();

    $component = Livewire::test(Form::class, ['product' => $product]);

    if ($canEdit) {
        $component->assertSeeHtml('id="product-tab-seo"')->call('save')->assertHasNoErrors();
    } else {
        $component->assertSeeHtml('data-read-only')->call('save')->assertForbidden();
    }
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, true],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('owns every validated form field in exactly one tab', function () {
    $this->actingAs(staffUser());

    $form = Livewire::test(Form::class)->instance()->form;
    $rules = (new ReflectionMethod($form, 'rules'))->invoke($form);

    $orphans = collect(array_keys($rules))
        ->map(fn (string $key): string => 'form.'.$key)
        ->reject(fn (string $key): bool => collect(array_keys(Form::TAB_FIELDS))->contains(fn (string $tab): bool => Form::tabOwnsAny($tab, [$key])))
        ->values()
        ->all();

    expect($orphans)->toBe([]);

    foreach (array_keys($rules) as $key) {
        $owners = collect(array_keys(Form::TAB_FIELDS))->filter(fn (string $tab): bool => Form::tabOwnsAny($tab, ['form.'.$key]));

        expect($owners)->toHaveCount(1);
    }
});

it('dispatches the tab for an invalid product type too', function () {
    $this->actingAs(staffUser());

    Livewire::test(Form::class)
        ->set('form.type', 'nonsense')
        ->call('save')
        ->assertHasErrors(['form.type'])
        ->assertDispatched('product-tab-errors', tab: 'main');
});
