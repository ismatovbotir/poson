<?php

use App\Enums\PriceSyncStatus;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\LimitJsonBody;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Models\Product;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->plain = fakeApiTokenValue();
    $this->token = ApiToken::factory()->withPlainText($this->plain)->create(['name' => '1C office']);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(function () {
    // Never the token or the Authorization header in the logs.
    expect(implode("\n", $this->logged))->not->toContain(substr($this->plain, 4, 20))->not->toContain('Bearer');
});

describe('authentication', function () {
    it('accepts a valid token and updates the prices', function () {
        $product = Product::factory()->create(['sku' => 'ZD421', 'price' => 100_00]);

        postPrices(['items' => [priceItem('zd421 ', 1500)]], $this->plain)
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('items.0', ['code' => 'ZD421', 'status' => 'updated']);

        expect($product->fresh()->price)->toBe(1500_00);
    });

    it('answers 401 JSON for missing, garbage, unknown, revoked, expired and wrong-ability tokens', function (Closure $makeToken) {
        $plain = $makeToken();

        postPrices(['items' => [priceItem('A')]], $plain)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['error' => 'unauthenticated', 'message' => 'A valid API token is required.']);

        expect(PriceSyncLog::query()->count())->toBe(0);
    })->with([
        'missing' => [fn () => null],
        'garbage' => [fn () => 'not-a-token'],
        'right shape, unknown' => [fn () => fakeApiTokenValue('zZ9')],
        'revoked' => [function () {
            ApiToken::factory()->withPlainText($plain = fakeApiTokenValue('rV1'))->revoked()->create();

            return $plain;
        }],
        'expired' => [function () {
            ApiToken::factory()->withPlainText($plain = fakeApiTokenValue('eX2'))->expired()->create();

            return $plain;
        }],
        'wrong ability' => [function () {
            ApiToken::factory()->withPlainText($plain = fakeApiTokenValue('aB3'))->abilities(['stock.write'])->create();

            return $plain;
        }],
    ]);

    it('rejects a token sent outside the Bearer header', function () {
        $this->call('POST', '/api/v1/prices', ['token' => $this->plain], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['items' => [priceItem('A')]]))
            ->assertUnauthorized();
    });

    it('blocks an IP after 10 failed attempts per minute, before any lookup', function () {
        foreach (range(1, AuthenticateApiToken::MAX_FAILURES) as $attempt) {
            postPrices(['items' => [priceItem('A')]], fakeApiTokenValue('xY7'))->assertUnauthorized();
        }

        // Even the valid token is refused now, and no query looks the token up.
        $queries = countQueries(function () {
            postPrices(['items' => [priceItem('A')]], $this->plain)
                ->assertTooManyRequests()
                ->assertJsonPath('error', 'too_many_failed_attempts')
                ->assertHeader('Retry-After');
        });

        expect($queries)->toBe(0);

        $this->travel(61)->seconds();

        postPrices(['items' => [priceItem('A')]], $this->plain)->assertOk();
    });

    it('writes last_used at most once a minute', function () {
        postPrices(['items' => [priceItem('A')]], $this->plain)->assertOk();
        $first = $this->token->fresh()->last_used_at;

        expect($first)->not->toBeNull()
            ->and($this->token->fresh()->last_used_ip)->toBe('127.0.0.1');

        $this->travel(30)->seconds();
        postPrices(['items' => [priceItem('A')]], $this->plain)->assertOk();
        expect($this->token->fresh()->last_used_at->equalTo($first))->toBeTrue();

        $this->travel(40)->seconds();
        postPrices(['items' => [priceItem('A')]], $this->plain)->assertOk();
        expect($this->token->fresh()->last_used_at->gt($first))->toBeTrue();
    });
});

describe('request limits', function () {
    it('answers 413 for a body over 256 KB before authentication', function () {
        $body = json_encode(['items' => [priceItem(str_repeat('A', LimitJsonBody::MAX_BYTES))]]);

        postPrices($body, null)
            ->assertStatus(413)
            ->assertJsonPath('error', 'payload_too_large');

        postPrices('{}', null, ['CONTENT_LENGTH' => (string) (LimitJsonBody::MAX_BYTES + 1)])
            ->assertStatus(413);
    });

    it('answers 415 for a non-JSON content type and logs the rejected batch', function (string $contentType) {
        postPrices(['items' => [priceItem('A')]], $this->plain, ['CONTENT_TYPE' => $contentType])
            ->assertStatus(415)
            ->assertJsonPath('error', 'unsupported_media_type');

        expect(PriceSyncLog::query()->sole())
            ->status->toBe(PriceSyncStatus::Rejected)
            ->error->toBe('unsupported_media_type')
            ->api_token_id->toBe($this->token->id)
            ->token_name->toBe('1C office');
    })->with(['form' => 'application/x-www-form-urlencoded', 'text' => 'text/plain', 'xml' => 'application/xml']);

    it('accepts application/json with a charset', function () {
        postPrices(['items' => [priceItem('A')]], $this->plain, ['CONTENT_TYPE' => 'application/json; charset=utf-8'])->assertOk();
    });

    it('rejects the whole batch with 422', function (string $body, string $error) {
        $response = postPrices($body, $this->plain)
            ->assertUnprocessable()
            ->assertJsonPath('error', $error)
            ->assertJsonStructure(['sync_id', 'error', 'message']);

        expect(PriceSyncLog::query()->sole())
            ->id->toBe($response->json('sync_id'))
            ->status->toBe(PriceSyncStatus::Rejected)
            ->error->toBe($error);
    })->with([
        'invalid json' => ['{"items": [', 'invalid_json'],
        'not an object' => ['[1, 2]', 'body_not_object'],
        'scalar' => ['42', 'body_not_object'],
        'empty array' => ['[]', 'body_not_object'],
        'null' => ['null', 'body_not_object'],
        'empty object' => ['{}', 'items_missing'],
        'items an empty object' => ['{"items": {}}', 'items_not_list'],
        'items missing' => ['{"products": []}', 'items_missing'],
        'items not a list' => ['{"items": {"a": 1}}', 'items_not_list'],
        'items a string' => ['{"items": "x"}', 'items_not_list'],
        'items empty' => ['{"items": []}', 'items_empty'],
        'too many items' => [json_encode(['items' => array_map(fn (int $i) => priceItem("C{$i}"), range(1, 101))]), 'too_many_items'],
    ]);

    it('allows 30 batches per minute per token, then 429', function () {
        $other = ApiToken::factory()->withPlainText($otherPlain = fakeApiTokenValue('oT4'))->create();

        foreach (range(1, 30) as $attempt) {
            postPrices(['items' => [priceItem('A')]], $this->plain)->assertOk();
        }

        postPrices(['items' => [priceItem('A')]], $this->plain)
            ->assertTooManyRequests()
            ->assertJsonPath('error', 'rate_limited');

        // Another token has its own budget.
        postPrices(['items' => [priceItem('A')]], $otherPlain)->assertOk();

        // Throttled requests are not authentication failures.
        expect(RateLimiter::attempts(AuthenticateApiToken::failureKey('127.0.0.1')))->toBe(0);
    });

    it('is only reachable with POST', function () {
        $this->getJson('/api/v1/prices')->assertStatus(405);
    });
});
