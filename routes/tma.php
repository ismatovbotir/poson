<?php

use App\Http\Controllers\Tma\AuthController;
use App\Http\Controllers\Tma\BootstrapController;
use App\Http\Controllers\Tma\CartController;
use App\Http\Controllers\Tma\CategoryController;
use App\Http\Controllers\Tma\CheckoutController;
use App\Http\Controllers\Tma\HomeController;
use App\Http\Controllers\Tma\OrderController;
use App\Http\Controllers\Tma\ProductController;
use App\Http\Controllers\Tma\QuoteController;
use App\Http\Controllers\Tma\SearchController;
use App\Http\Controllers\Tma\ServiceRequestController;
use App\Http\Controllers\Tma\UnavailableController;
use App\Support\Cart\CartLineKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telegram Mini App (plan 20)
|--------------------------------------------------------------------------
|
| Loaded with prefix('tma')->name('tma.')->middleware('tma') in
| bootstrap/app.php: plain Blade + tma.js, no Livewire, own session
| (ConfigureTmaSession), TmaHeaders (CSP, noindex). Every page after the
| sign-in needs EnsureTmaUser ("tma.user"). Cart and checkout routes are a
| 404 while config site.cart_enabled is off (in the controllers).
|
*/

Route::get('/', BootstrapController::class)->name('bootstrap');
Route::post('/auth', AuthController::class)->middleware('throttle:tma-auth')->name('auth');

Route::prefix('{locale}')
    ->whereIn('locale', config('app.supported_locales'))
    ->middleware('tma.locale')
    ->group(function (): void {
        Route::get('/unavailable/{reason}', UnavailableController::class)
            ->whereIn('reason', UnavailableController::REASONS)
            ->name('unavailable');

        Route::middleware(['tma.user', 'throttle:tma'])->group(function (): void {
            Route::get('/', HomeController::class)->name('home');
            Route::get('/c/{category:slug}', CategoryController::class)->name('category');
            Route::get('/c/{category:slug}/filters', [CategoryController::class, 'filters'])->name('category.filters');
            Route::get('/search', SearchController::class)->name('search');
            Route::get('/p/{product:slug}', ProductController::class)->name('product');
            Route::post('/p/{product:slug}/quote', QuoteController::class)->name('quote');
            Route::get('/p/{product:slug}/service', [ServiceRequestController::class, 'show'])->name('service');
            Route::post('/p/{product:slug}/service', [ServiceRequestController::class, 'store'])->name('service.store');

            Route::get('/cart', [CartController::class, 'show'])->name('cart');
            Route::post('/cart', [CartController::class, 'add'])->middleware('throttle:tma-cart')->name('cart.add');
            Route::post('/cart/quote', [CartController::class, 'quote'])->name('cart.quote');
            Route::post('/cart/{lineKey}', [CartController::class, 'update'])->where('lineKey', CartLineKey::ROUTE_PATTERN)->middleware('throttle:tma-cart')->name('cart.update');
            Route::post('/cart/{lineKey}/remove', [CartController::class, 'remove'])->where('lineKey', CartLineKey::ROUTE_PATTERN)->middleware('throttle:tma-cart')->name('cart.remove');

            Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
            Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

            Route::get('/orders', [OrderController::class, 'index'])->name('orders');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        });
    });
