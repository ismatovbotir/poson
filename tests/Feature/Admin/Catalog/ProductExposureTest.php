<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Products\Form;
use App\Livewire\Admin\Products\Index;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('exposes products publicly only through the product page, its document download and the partner redirect', function () {
    $public = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'admin') || str_starts_with($route->uri(), 'livewire'))
        ->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'product') || str_contains((string) $route->getName(), 'product'));

    // All check Product::isVisible() (ProductPageTest, PartnerRedirectTest); the redirect exposes only the software brand's link.
    expect($public->map(fn (RoutingRoute $route) => $route->uri())->sort()->values()->all())->toBe([
        '{locale}/go/{product}',
        '{locale}/product/{product}',
        '{locale}/product/{product}/documents/{document}',
    ]);
});

it('never shows Dealer or Wholesale prices on the public home page', function (string $locale) {
    Product::factory()->featured()->create([
        'price' => 123_456_700,
        'price_dealer' => 987_654_300,
        'price_wholesale' => 876_543_200,
    ]);

    $html = $this->get("/{$locale}")->assertOk()->getContent();

    expect($html)->not->toContain('9 876 543')->not->toContain('9876543')
        ->not->toContain('8 765 432')->not->toContain('8765432');
})->with(['uz', 'ru', 'en']);

it('uses no discount or low-price wording in the admin product screens', function (string $locale) {
    $category = Category::factory()->create();
    $category->attributes()->attach(Attribute::factory()->select()->create(), ['sort' => 1, 'is_required' => true]);
    $product = Product::factory()->inCategory($category)->create(['has_missing_specs' => true]);
    Product::factory()->priceOnRequest()->create();
    $user = staffUser();
    $user->forceFill(['locale' => $locale])->save();
    app()->setLocale($locale);

    // The product views only (the shared admin layout has its own tests).
    $pages = [
        Livewire::actingAs($user)->test(Index::class)->html(),
        Livewire::actingAs($user)->test(Index::class)->set('tab', 'trash')->html(),
        Livewire::actingAs($user)->test(Form::class)->html(),
        Livewire::actingAs($user)->test(Form::class, ['product' => $product])->html(),
    ];

    foreach ($pages as $html) {
        expect($html)->toContain(e(__('admin.products.title')));
        expectNoBannedWording($html);
    }

    // Lang strings that only appear after actions (toasts, errors).
    expectNoBannedWording(json_encode(__('admin.products'), JSON_UNESCAPED_UNICODE));
})->with(['uz', 'ru', 'en']);

it('shows the product cards on the dashboard', function () {
    Product::factory()->count(2)->create();
    Product::factory()->inactive()->create(['has_missing_specs' => true]);
    Product::factory()->inCategory(Category::factory()->inactive()->create())->create(); // not on the site

    $cards = collect(Livewire::actingAs(staffUser())->test(Dashboard::class)->get('cards'))->pluck('count', 'key');

    expect($cards['products_active'])->toBe(2)
        ->and($cards['products_drafts'])->toBe(1)
        ->and($cards['products_incomplete'])->toBe(1);
});

it('renders the admin product screens without lazy loading', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())->test(Index::class)->assertOk();
    Livewire::actingAs(staffUser())->test(Form::class, ['product' => $product])->assertOk();
})->throwsNoExceptions();
