<?php

use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\UpdateLocaleController;
use App\Livewire\Admin\Attributes;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Brands;
use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Dashboard;
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
});
