<?php

use App\Support\CompanySettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

it('falls back to config defaults when the settings table does not exist', function () {
    Exceptions::fake();

    $settings = app(CompanySettings::class);

    expect($settings->email())->toBe('info@poson.uz')
        ->and($settings->phone())->toBe('+998915010757')
        ->and(Cache::has(CompanySettings::CACHE_KEY))->toBeFalse();

    // Reported once: the empty result is memoized for the rest of the request.
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(QueryException::class);
});
