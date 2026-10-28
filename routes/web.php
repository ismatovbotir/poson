<?php

use App\Http\Controllers\RedirectToLocale;
use App\Http\Controllers\Site\DownloadProductDocumentController;
use App\Http\Controllers\Site\LlmsController;
use App\Http\Controllers\Site\NewsFeedController;
use App\Http\Controllers\Site\PartnerRedirectController;
use App\Http\Controllers\Site\PublicQuoteController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use App\Livewire\Site\Articles;
use App\Livewire\Site\Brands;
use App\Livewire\Site\Cart;
use App\Livewire\Site\Catalog;
use App\Livewire\Site\Home;
use App\Livewire\Site\News;
use App\Livewire\Site\Pages;
use App\Livewire\Site\Search;
use Illuminate\Support\Facades\Route;

Route::get('/', RedirectToLocale::class)->name('site.root');

// Crawler files (no locale prefix).
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/llms.txt', LlmsController::class)->name('llms');

// Plan 06: the customer's quote link (no login, unguessable ULID, noindex, throttled).
Route::get('/q/{ulid}', [PublicQuoteController::class, 'show'])->whereUlid('ulid')->middleware('throttle:quote-page')->name('quotes.public');
Route::get('/q/{ulid}/pdf', [PublicQuoteController::class, 'pdf'])->whereUlid('ulid')->middleware('throttle:quote-pdf')->name('quotes.public.pdf');

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
        // "Try" (ProductCtas): partner click + 302 to the partner signup URL; noindex, robots.txt disallows /*/go/.
        Route::get('/go/{product:slug}', PartnerRedirectController::class)
            ->middleware('throttle:30,1,partner')
            ->name('product.partner');

        // Brands (decision 2026-10-24): active brands with published products; a hidden or unknown brand is a 404.
        Route::livewire('/brands', Brands\Index::class)->name('brands.index');
        Route::livewire('/brands/{brand:slug}', Brands\Show::class)->name('brands.show');

        Route::livewire('/search', Search::class)->name('search');

        // Cart (plan 11a): 404 while config site.cart_enabled is off; noindex, robots.txt disallows /*/cart.
        Route::livewire('/cart', Cart\Page::class)->name('cart');

        // Static pages (plan 07 7a): 404 unless active.
        Route::livewire('/page/{page:slug}', Pages\Show::class)->name('page');

        // News (plan 07 7b): only visible posts, only in their translated locales (404 elsewhere).
        Route::livewire('/news', News\Index::class)->name('news.index');
        Route::get('/news/rss.xml', NewsFeedController::class)->middleware('throttle:60,1,news-rss')->name('news.rss');
        Route::livewire('/news/{newsPost:slug}', News\Show::class)->where('newsPost', '[a-z0-9-]+')->name('news.show');

        // Knowledge articles (decision 2026-10-24): news_posts with kind = article, same rules, no RSS.
        Route::livewire('/articles', Articles\Index::class)->name('articles.index');
        Route::livewire('/articles/{newsPost:slug}', Articles\Show::class)->where('newsPost', '[a-z0-9-]+')->name('articles.show');
    });
