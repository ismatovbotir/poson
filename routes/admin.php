<?php

use App\Http\Controllers\Admin\DownloadProductDocumentController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\ServiceRequestsController;
use App\Http\Controllers\Admin\UpdateLocaleController;
use App\Livewire\Admin\Analytics;
use App\Livewire\Admin\Attributes;
use App\Livewire\Admin\Auth\ConfirmPassword;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Brands;
use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Leads;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Settings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
|
| Loaded in bootstrap/app.php with prefix "admin", name "admin." and the
| "web" middleware group. There is deliberately no registration route:
| staff accounts are created with `php artisan admin:create-user`.
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

    // Products (plan 03 3c). Images, documents and relations are child components of the form.
    Route::livewire('/products', Products\Index::class)->name('products.index');
    Route::livewire('/products/create', Products\Form::class)->name('products.create');
    Route::livewire('/products/{product}/edit', Products\Form::class)->name('products.edit');
    Route::get('/products/documents/{document}/download', DownloadProductDocumentController::class)->name('products.documents.download');

    // Settings (plan 04a). Admin only through settings.company; authorized in the component.
    Route::livewire('/settings/company', Settings\Company::class)->name('settings.company');

    // Integrations (plan 04b): settings.integrations (Admin only) + password re-confirmation within 15 minutes.
    Route::livewire('/settings/integrations', Settings\Integrations::class)->name('settings.integrations');
    Route::livewire('/confirm-password', ConfirmPassword::class)->name('confirm-password');
});
