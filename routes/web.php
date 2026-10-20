<?php

use App\Http\Controllers\RedirectToLocale;
use Illuminate\Support\Facades\Route;

Route::get('/', RedirectToLocale::class)->name('site.root');

Route::prefix('{locale}')
    ->whereIn('locale', config('app.supported_locales'))
    ->middleware('locale')
    ->name('site.')
    ->group(function () {
        // TEMPORARY placeholder home. T5 replaces it with the Livewire page.
        Route::view('/', 'site.home-placeholder')->name('home');
    });
