<?php

use App\Http\Controllers\RedirectToLocale;
use App\Http\Controllers\Site\DownloadProductDocumentController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use App\Livewire\Site\Catalog;
use App\Livewire\Site\Home;
use App\Livewire\Site\Search;
use Illuminate\Support\Facades\Route;

Route::get('/', RedirectToLocale::class)->name('site.root');

// Crawler files (no locale prefix).
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

Route::prefix('{locale}')
    ->whereIn('locale', config('app.supported_locales'))
    ->middleware('locale')
    ->name('site.')
    ->group(function () {
        Route::livewire('/', Home::class)->name('home');

        Route::livewire('/catalog', Catalog\Index::class)->name('catalog.index');
        Route::livewire('/catalog/{category:slug}', Catalog\Index::class)->name('catalog.category');
        Route::livewire('/product/{product:slug}', Catalog\Show::class)->name('product');
        Route::get('/product/{product:slug}/documents/{document}', DownloadProductDocumentController::class)
            ->scopeBindings()
            ->middleware('throttle:60,1')
            ->name('product.document');

        Route::livewire('/search', Search::class)->name('search');
    });
