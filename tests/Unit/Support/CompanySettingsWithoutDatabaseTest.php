<?php

use App\Support\CompanySettings;
use Illuminate\Support\Facades\Cache;

it('falls back to config defaults when the settings table does not exist', function () {
    $settings = app(CompanySettings::class);

    expect($settings->email())->toBe('info@poson.uz')
        ->and($settings->phone())->toBe('+998000000000')
        ->and(Cache::has(CompanySettings::CACHE_KEY))->toBeFalse();
});
