<?php

use App\Http\Controllers\Api\SyncPricesController;
use App\Http\Middleware\LimitJsonBody;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Machine API (plan 09)
|--------------------------------------------------------------------------
|
| Loaded in bootstrap/app.php with the "api" prefix and middleware group
| (no Sanctum: custom api_tokens, App\Http\Middleware\AuthenticateApiToken).
| Order: body size (413) → token (401) → per-token limit (429).
| Contract for the 1C developer: .claude/docs/api/prices.md.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/prices', SyncPricesController::class)
        ->middleware([LimitJsonBody::class, 'api.token:'.ApiToken::ABILITY_PRICES_WRITE, 'throttle:price-api'])
        ->name('prices');
});
