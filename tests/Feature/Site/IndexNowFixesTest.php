<?php

use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Jobs\SubmitIndexNow;
use App\Support\Site\IndexNow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function activateIndexNowFixes(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.url' => 'https://poson.example.uz']);
    openSearchIndexing();
    app(SetSecret::class)->handle('indexnow.key', 'abcdef0123456789abcdef0123456789', null, null);
    app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => true], null, null);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('serves the key file revalidated, never cached for long', function () {
    activateIndexNowFixes();

    $response = $this->get('https://poson.example.uz/abcdef0123456789abcdef0123456789.txt')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('max-age=3600');
});

it('does not follow redirects and fails the batch on a 307', function () {
    activateIndexNowFixes();
    Http::fake(['*' => Http::response('', 307, ['Location' => 'https://evil.example/x'])]);
    Cache::put(IndexNow::INFLIGHT_KEY, true, 600);

    $job = new SubmitIndexNow(['https://poson.example.uz/uz']);
    $job->withFakeQueueInteractions();
    $job->handle();

    Http::assertSentCount(1);
    $job->assertFailed();
});

it('ends retries before the in-flight flag expires', function () {
    $job = new SubmitIndexNow([]);

    expect($job->retryUntil()->getTimestamp())->toBeLessThan(now()->addSeconds(IndexNow::INFLIGHT_SECONDS)->getTimestamp());
});
