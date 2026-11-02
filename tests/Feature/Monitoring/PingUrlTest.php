<?php

use App\Support\Integrations\IntegrationRegistry;
use App\Support\Monitoring\PingUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

const PING_OK = 'https://hc-ping.com/11111111-2222-3333-4444-555555555555';

it('accepts only https URLs on an allowed host', function (string $url, bool $allowed) {
    expect(PingUrl::isAllowed($url))->toBe($allowed);
})->with([
    'hc-ping uuid' => [PING_OK, true],
    'healthchecks subdomain' => ['https://hc.healthchecks.io/ping/abc', true],
    'loopback' => ['https://127.0.0.1/ping', false],
    'metadata ip' => ['https://169.254.169.254/latest/meta-data', false],
    'plain http' => ['http://hc-ping.com/abc', false],
    'userinfo trick' => ['https://hc-ping.com@evil.example/abc', false],
    'userinfo trick 2' => ['https://evil.example@hc-ping.com.evil.example/abc', false],
    'suffix host' => ['https://hc-ping.com.evil.example/abc', false],
    'bare healthchecks apex' => ['https://healthchecks.io/abc', false],
    'path trick' => ['https://evil.example/hc-ping.com', false],
    'custom port' => ['https://hc-ping.com:8443/abc', false],
    'backslash' => ['https://evil.example\\@hc-ping.com/abc', false],
    'empty' => ['', false],
]);

it('validates through the registry rule', function () {
    $rules = IntegrationRegistry::field('monitoring.ping_url')->rules;

    expect(Validator::make(['v' => PING_OK], ['v' => $rules])->passes())->toBeTrue()
        ->and(Validator::make(['v' => 'https://127.0.0.1/x'], ['v' => $rules])->fails())->toBeTrue()
        ->and(IntegrationRegistry::field('monitoring.ping_url')->secret)->toBeTrue();
});

it('does not follow redirects and never requests a disallowed URL', function () {
    Http::fake(['hc-ping.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
    freshSecrets(['services.monitoring.ping_url' => PING_OK]);

    expect(PingUrl::send())->toBeFalse();
    Http::assertSentCount(1);

    Http::fake();
    freshSecrets(['services.monitoring.ping_url' => 'https://127.0.0.1/x']);

    expect(PingUrl::send())->toBeFalse();
    Http::assertNothingSent();
});

it('does nothing without a configured URL', function () {
    Http::fake();
    freshSecrets();

    expect(PingUrl::send())->toBeFalse();
    Http::assertNothingSent();
});
