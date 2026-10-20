<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/ip', fn () => request()->ip());
});

it('ignores X-Forwarded-For when no proxy is trusted', function () {
    config(['app.trusted_proxies' => []]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeader('X-Forwarded-For', '203.0.113.7')
        ->get('/_test/ip')
        ->assertOk()
        ->assertSeeText('10.0.0.5');
});

it('uses X-Forwarded-For from a trusted proxy', function () {
    config(['app.trusted_proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeader('X-Forwarded-For', '203.0.113.7')
        ->get('/_test/ip')
        ->assertOk()
        ->assertSeeText('203.0.113.7');
});

it('ignores X-Forwarded-For from an untrusted address', function () {
    config(['app.trusted_proxies' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->withHeader('X-Forwarded-For', '203.0.113.7')
        ->get('/_test/ip')
        ->assertOk()
        ->assertSeeText('198.51.100.9');
});
