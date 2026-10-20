<?php

use App\Http\Controllers\RedirectToLocale;
use App\Livewire\Site\Home;
use Illuminate\Support\Facades\Route;

Route::get('/', RedirectToLocale::class)->name('site.root');

Route::prefix('{locale}')
    ->whereIn('locale', config('app.supported_locales'))
    ->middleware('locale')
    ->name('site.')
    ->group(function () {
        Route::livewire('/', Home::class)->name('home');
    });
