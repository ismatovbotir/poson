<?php

use App\Http\Controllers\Admin\DownloadProductDocumentController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\OrderInvoiceController;
use App\Http\Controllers\Admin\QuotePdfController;
use App\Http\Controllers\Admin\ServiceRequestsController;
use App\Http\Controllers\Admin\UpdateLocaleController;
use App\Livewire\Admin\Analytics;
use App\Livewire\Admin\Articles;
use App\Livewire\Admin\Attributes;
use App\Livewire\Admin\Auth\ConfirmPassword;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Banners;
use App\Livewire\Admin\Brands;
use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Customers;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Leads;
use App\Livewire\Admin\News;
use App\Livewire\Admin\Orders;
use App\Livewire\Admin\Pages;
use App\Livewire\Admin\Payments;
use App\Livewire\Admin\PriceSync;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Quotes;
use App\Livewire\Admin\RollTypes;
use App\Livewire\Admin\Settings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
|
| Loaded in bootstrap/app.php with prefix "admin", name "admin." and the
| "web" middleware group. There is deliberately no registration route:
| the first Admin is created by FirstAdminSeeder (ADMIN_SEED_*).
|
*/

Route::middleware(['guest', 'admin.locale'])->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

// UI language: users.locale for staff, the session on the login page.
Route::post('/locale', UpdateLocaleController::class)
    ->middleware(['admin.locale', 'throttle:20,1'])
    ->name('locale');

// Outside "admin" so a deactivated user can still end the session.
Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'admin.locale', 'admin'])->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    // CRM: Murojaatlar (plan 05 5a). LeadPolicy in the components; lists use Lead::visibleTo().
    Route::livewire('/leads', Leads\Index::class)->name('leads.index');
    Route::livewire('/leads/create', Leads\Form::class)->name('leads.create');
    Route::livewire('/leads/{lead}', Leads\Show::class)->whereNumber('lead')->name('leads.show');
    Route::livewire('/leads/{lead}/edit', Leads\Form::class)->whereNumber('lead')->name('leads.edit');
    Route::get('/service-requests', ServiceRequestsController::class)->name('service-requests.index');

    // CRM: customers (plan 05b). CustomerPolicy in the components; lists use Customer::visibleTo().
    Route::livewire('/customers', Customers\Index::class)->name('customers.index');
    Route::livewire('/customers/create', Customers\Form::class)->name('customers.create');
    Route::livewire('/customers/{customer}', Customers\Show::class)->whereNumber('customer')->name('customers.show');
    Route::livewire('/customers/{customer}/edit', Customers\Form::class)->whereNumber('customer')->name('customers.edit');

    // Quotes (plan 06). QuotePolicy in the components; lists use Quote::visibleTo().
    Route::livewire('/quotes', Quotes\Index::class)->name('quotes.index');
    Route::livewire('/quotes/create', Quotes\Form::class)->name('quotes.create');
    Route::livewire('/quotes/{quote}', Quotes\Show::class)->whereNumber('quote')->name('quotes.show');
    Route::livewire('/quotes/{quote}/edit', Quotes\Form::class)->whereNumber('quote')->name('quotes.edit');
    Route::get('/quotes/{quote}/pdf', QuotePdfController::class)->whereNumber('quote')->name('quotes.pdf');

    // Orders and payments (plan 11b). OrderPolicy in the components; lists use Order::visibleTo().
    Route::livewire('/orders', Orders\Index::class)->name('orders.index');
    Route::livewire('/orders/create', Orders\Form::class)->name('orders.create');
    Route::livewire('/orders/{order}', Orders\Show::class)->whereNumber('order')->name('orders.show');
    Route::livewire('/orders/{order}/edit', Orders\Form::class)->whereNumber('order')->name('orders.edit');
    Route::get('/orders/{order}/invoice', OrderInvoiceController::class)->whereNumber('order')->name('orders.invoice');
    Route::livewire('/payments', Payments\Index::class)->name('payments.index');

    // Analytics (plan 04c): analytics.view (Admin only), authorized in the components.
    Route::livewire('/analytics', Analytics\Traffic::class)->name('analytics.index');
    Route::livewire('/analytics/ai', Analytics\AiVisibility::class)->name('analytics.ai');

    // Catalog (plan 03 3b). Each component authorizes in mount() and before writes.
    Route::livewire('/categories', Categories\Index::class)->name('categories.index');
    Route::livewire('/categories/create', Categories\Form::class)->name('categories.create');
    Route::livewire('/categories/{category}/edit', Categories\Form::class)->name('categories.edit');

    Route::livewire('/attributes', Attributes\Index::class)->name('attributes.index');
    Route::livewire('/attributes/create', Attributes\Form::class)->name('attributes.create');
    Route::livewire('/attributes/{attribute}/edit', Attributes\Form::class)->name('attributes.edit');

    Route::livewire('/brands', Brands\Index::class)->name('brands.index');
    Route::livewire('/brands/create', Brands\Form::class)->name('brands.create');
    Route::livewire('/brands/{brand}/edit', Brands\Form::class)->name('brands.edit');

    // Label roll types (plan 08): roll-types.* in the components; products attach them on the product form.
    Route::livewire('/roll-types', RollTypes\Index::class)->name('roll-types.index');
    Route::livewire('/roll-types/create', RollTypes\Form::class)->name('roll-types.create');
    Route::livewire('/roll-types/{rollType}/edit', RollTypes\Form::class)->whereNumber('rollType')->name('roll-types.edit');

    // Products (plan 03 3c). Images, documents and relations are child components of the form.
    Route::livewire('/products', Products\Index::class)->name('products.index');
    Route::livewire('/products/create', Products\Form::class)->name('products.create');
    Route::livewire('/products/{product}/edit', Products\Form::class)->name('products.edit');
    Route::get('/products/documents/{document}/download', DownloadProductDocumentController::class)->name('products.documents.download');

    // Price API log (plan 09): price-sync.view (Admin only), authorized in the components.
    Route::livewire('/price-sync', PriceSync\Index::class)->name('price-sync.index');
    Route::livewire('/price-sync/{priceSyncLog}', PriceSync\Show::class)->whereNumber('priceSyncLog')->name('price-sync.show');

    // Content (plan 07 7a): static pages. PagePolicy in the components; system pages are never deleted.
    Route::livewire('/pages', Pages\Index::class)->name('pages.index');
    Route::livewire('/pages/create', Pages\Form::class)->name('pages.create');
    Route::livewire('/pages/{page}/edit', Pages\Form::class)->whereNumber('page')->name('pages.edit');

    // Content (plan 07 7b): news. NewsPostPolicy in the components.
    Route::livewire('/news', News\Index::class)->name('news.index');
    Route::livewire('/news/create', News\Form::class)->name('news.create');
    Route::livewire('/news/{newsPost}/edit', News\Form::class)->whereNumber('newsPost')->name('news.edit');

    // Knowledge articles (decision 2026-10-24): the news screens for kind = article, same news.* permissions.
    Route::livewire('/articles', Articles\Index::class)->name('articles.index');
    Route::livewire('/articles/create', Articles\Form::class)->name('articles.create');
    Route::livewire('/articles/{newsPost}/edit', Articles\Form::class)->whereNumber('newsPost')->name('articles.edit');

    // Content (plan 07 7c): banners (home slider, category listings). BannerPolicy in the components.
    Route::livewire('/banners', Banners\Index::class)->name('banners.index');
    Route::livewire('/banners/create', Banners\Form::class)->name('banners.create');
    Route::livewire('/banners/{banner}/edit', Banners\Form::class)->whereNumber('banner')->name('banners.edit');

    // Settings (plan 04a). Admin only through settings.company; authorized in the component.
    Route::livewire('/settings/company', Settings\Company::class)->name('settings.company');

    // Integrations (plan 04b): settings.integrations (Admin only) + password re-confirmation within 15 minutes.
    Route::livewire('/settings/integrations', Settings\Integrations::class)->name('settings.integrations');
    // Checkout settings (plan 11b): settings.checkout (Admin only): delivery fees, caps, IKPU, UzQR.
    Route::livewire('/settings/checkout', Settings\Checkout::class)->name('settings.checkout');
    // Search indexing switch (plan 15 C): Admin only (manageArea Indexing) + password re-confirmation for saving.
    Route::livewire('/settings/indexing', Settings\Indexing::class)->name('settings.indexing');
    // Price API tokens (plan 09): api-tokens.manage (Admin only) + password re-confirmation for writes.
    Route::livewire('/settings/api-tokens', Settings\ApiTokens::class)->name('settings.api-tokens');

    Route::livewire('/confirm-password', ConfirmPassword::class)->name('confirm-password');
});
