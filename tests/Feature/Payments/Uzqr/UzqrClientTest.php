<?php

use App\Enums\PaymentMode;
use App\Services\Payments\Uzqr\UzqrAmount;
use App\Services\Payments\Uzqr\UzqrAuth;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;
use App\Services\Payments\Uzqr\UzqrQrText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
    Uzqr::clearLog();
});

function uzqrClient(PaymentMode $mode = PaymentMode::Prod): UzqrClient
{
    return app(UzqrClient::class)->forMode($mode, true);
}

it('builds the Auth header from a fixed vector (STIR and PINFL)', function () {
    expect(UzqrAuth::header('305123456', 'secret-key', 1715000000))->toBe('305123456:'.sha1('1715000000secret-key').':1715000000')
        ->and(UzqrAuth::header('31234567890123', 'secret-key', 1715000000))->toBe('31234567890123:'.sha1('1715000000secret-key').':1715000000')
        ->and(sha1('1715000000secret-key'))->toMatch('/^[0-9a-f]{40}$/');
});

it('accepts only STIR (9 digits, 2–6) and PINFL (14 digits, 3–6) as INN', function (string $inn, bool $valid) {
    expect(UzqrAuth::validInn($inn))->toBe($valid);
})->with([
    ['305123456', true], ['612345678', true], ['105123456', false], ['30512345', false],
    ['31234567890123', true], ['21234567890123', false], ['3123456789012a', false],
]);

it('formats amounts from tiyin with integer math', function (int $tiyin, string $expected) {
    expect(UzqrAmount::format($tiyin))->toBe($expected);
})->with([[1, '0.01'], [10000, '100.00'], [123456789, '1234567.89'], [100001, '1000.01'], [5_000_000_000_00, '5000000000.00']]);

it('refuses a zero amount and parses soʻm strings back to tiyin', function () {
    expect(fn () => UzqrAmount::format(0))->toThrow(InvalidArgumentException::class)
        ->and(UzqrAmount::toTiyin('100.01'))->toBe(10001)
        ->and(UzqrAmount::toTiyin('100.5'))->toBe(10050)
        ->and(UzqrAmount::toTiyin('100'))->toBe(10000)
        ->and(UzqrAmount::toTiyin('1e3'))->toBeNull();
});

it('sends a fresh Auth header per request and never logs it, the key or the QR text', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::created('INV1', 'https://qr.example.uz/secret-qr')]);

    $invoice = uzqrClient()->createInvoice('PS000000000001', 1_000_000_00);

    expect($invoice->invoiceId)->toBe('INV1')->and($invoice->qrText)->toBe('https://qr.example.uz/secret-qr');

    Http::assertSent(function (Request $request): bool {
        [$inn, $digest, $timestamp] = explode(':', Uzqr::authHeader($request));

        return $inn === Uzqr::INN
            && strlen($timestamp) === 10 && abs((int) $timestamp - time()) < 5
            && hash_equals(sha1($timestamp.Uzqr::KEY), $digest)
            && $request['external_id'] === 'PS000000000001'
            && $request['amount'] === '1000000.00'
            && $request->method() === 'POST';
    });

    $log = Uzqr::log();
    expect($log)->toContain('invoice/create')->toContain('PS000000000001')
        ->not->toContain(Uzqr::KEY)->not->toContain('secret-qr')->not->toContain(sha1(substr((string) time(), 0, 10).Uzqr::KEY));
});

it('retries invoice/create on 5xx with the same external_id, never on 2002', function () {
    Http::fakeSequence(Uzqr::url('invoice/create'))->push(['error' => ['error_code' => 9003]], 503)->push(['invoice_id' => 'INV2', 'qr_text' => 'QR'], 200);

    expect(uzqrClient()->createInvoice('PS000000000002', 500)->invoiceId)->toBe('INV2');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['external_id'] === 'PS000000000002');
});

it('retries an interactive create once at most and never after a timeout; the background retries twice', function () {
    config(['uzqr.limits.upstream_per_second' => 50]);
    Http::fake([Uzqr::url('invoice/create') => Uzqr::error(9003, 503)]);
    expect(fn () => uzqrClient()->createInvoice('PS000000000003', 500))->toThrow(UzqrException::class);
    Http::assertSentCount(2);

    Http::fake([Uzqr::url('invoice/create') => Http::failedConnection('cURL error 28: Operation timed out')]);
    expect(fn () => uzqrClient()->createInvoice('PS000000000003', 500))->toThrow(fn (UzqrException $e) => expect($e->reason)->toBe(UzqrException::TIMEOUT));
    Http::assertSentCount(1);

    expect(fn () => app(UzqrClient::class)->forMode(PaymentMode::Prod)->createInvoice('PS000000000003', 500))->toThrow(UzqrException::class);
    Http::assertSentCount(4);
});

it('treats 2002 as permanent without retrying or leaking bank text', function () {
    Http::fake([Uzqr::url('invoice/create') => Uzqr::error(2002, 400)]);

    try {
        uzqrClient()->createInvoice('PS000000000003', 500);
        $this->fail('expected an exception');
    } catch (UzqrException $exception) {
        expect($exception->reason)->toBe(UzqrException::INVALID_AMOUNT)
            ->and($exception->isPermanent())->toBeTrue()
            ->and($exception->getMessage())->not->toContain('+998')->not->toContain('Bank');
    }

    Http::assertSentCount(1);
});

it('reads invoice status 1, 2 and 3 and keeps only the card type and last 4 digits', function () {
    Http::fakeSequence(Uzqr::url('invoice/status'))
        ->pushResponse(Uzqr::status(1, 'INV9'))
        ->pushResponse(Uzqr::status('2', 'INV9'))
        ->pushResponse(Uzqr::status(3, 'INV9'));

    $client = uzqrClient();

    expect($client->invoiceStatus('INV9')->status)->toBe(1);

    $paid = $client->invoiceStatus('INV9');
    expect($paid->status)->toBe(2)->and($paid->payId)->toBe(Uzqr::PAY_ID)->and($paid->cardType)->toBe(2)->and($paid->cardLast4)->toBe('1234')
        ->and(json_encode($paid))->not->toContain('+998')->not->toContain('8600');

    expect($client->invoiceStatus('INV9')->status)->toBe(3);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'invoice_id=INV9'));
});

it('rejects invalid status answers', function (array $body, string $reason) {
    Http::fake([Uzqr::url('invoice/status') => Http::response($body)]);

    expect(fn () => uzqrClient()->invoiceStatus('INV9'))->toThrow(fn (UzqrException $e) => expect($e->reason)->toBe($reason));
})->with([
    'other invoice' => [['invoice_id' => 'INV8', 'status' => 1], UzqrException::INVALID_RESPONSE],
    'status 4' => [['invoice_id' => 'INV9', 'status' => 4], UzqrException::INVALID_RESPONSE],
    'status text' => [['invoice_id' => 'INV9', 'status' => 'paid'], UzqrException::INVALID_RESPONSE],
    'paid without pay_id' => [['invoice_id' => 'INV9', 'status' => 2, 'pay_id' => null], UzqrException::MISSING_PAY_ID],
    'short pay_id' => [['invoice_id' => 'INV9', 'status' => 2, 'pay_id' => 'PAY1'], UzqrException::MISSING_PAY_ID],
    'list' => [[1, 2], UzqrException::INVALID_RESPONSE],
]);

it('rejects oversized responses and invalid QR texts', function () {
    Http::fake([Uzqr::url('invoice/status') => Http::response(json_encode(['invoice_id' => 'INV9', 'status' => 1, 'pad' => str_repeat('x', 17_000)]))]);
    expect(fn () => uzqrClient()->invoiceStatus('INV9'))->toThrow(UzqrException::class);

    Http::fake([Uzqr::url('invoice/create') => Http::response(['invoice_id' => 'INV1', 'qr_text' => "bad\nqr<script>"])]);
    expect(fn () => uzqrClient()->createInvoice('PS000000000004', 100))->toThrow(fn (UzqrException $e) => expect($e->reason)->toBe(UzqrException::INVALID_RESPONSE));

    expect(UzqrQrText::isValid(str_repeat('a', 2000)))->toBeTrue()
        ->and(UzqrQrText::isValid(str_repeat('a', 2001)))->toBeFalse()
        ->and(UzqrQrText::isValid(''))->toBeFalse();
});

it('maps bank errors to its own codes', function (int $code, int $http, string $reason, bool $transient) {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::error($code, $http)]);

    try {
        uzqrClient()->invoiceStatus('INV9');
        $this->fail('expected an exception');
    } catch (UzqrException $exception) {
        expect($exception->reason)->toBe($reason)->and($exception->isTransient())->toBe($transient)->and($exception->errorCode)->toBe($code);
    }
})->with([
    [1001, 401, UzqrException::AUTH_FAILED, false],
    [1002, 403, UzqrException::BLOCKED, false],
    [3001, 404, UzqrException::NOT_FOUND, false],
    [6001, 429, UzqrException::RATE_LIMITED, true],
    [9001, 500, UzqrException::SERVER_ERROR, true],
    [9004, 504, UzqrException::SERVER_ERROR, true],
]);

it('turns a connection failure into its own code without the URL', function () {
    Http::fake([Uzqr::url('invoice/status') => Http::failedConnection('cURL error 7: '.Uzqr::PROD)]);

    try {
        uzqrClient()->invoiceStatus('INV9');
    } catch (UzqrException $exception) {
        expect($exception->reason)->toBe(UzqrException::CONNECTION_FAILED)->and($exception->getMessage())->not->toContain('example.uz');
    }
});

it('does not follow redirects', function () {
    Http::fake([Uzqr::url('invoice/status') => Http::response('', 302, ['Location' => 'https://evil.example.com/'])]);

    expect(fn () => uzqrClient()->invoiceStatus('INV9'))->toThrow(UzqrException::class);
    Http::assertSentCount(1);
});

it('cancels an invoice and pings with an empty create (400 = credentials accepted)', function () {
    Http::fake([
        Uzqr::url('invoice/cancel') => Http::response('', 200),
        Uzqr::url('invoice/create') => Uzqr::error(2001, 400),
    ]);

    uzqrClient()->cancelInvoice('INV9');
    uzqrClient()->ping();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'invoice/cancel') && $request['invoice_id'] === 'INV9');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'invoice/create') && $request->body() === '{}');
});

it('accepts only https host base URLs without user info, query or IP literal', function (?string $url, bool $valid) {
    expect(UzqrConfig::validBaseUrl($url) !== null)->toBe($valid);
})->with([
    ['https://api.uzqr.example.uz', true],
    ['https://api.uzqr.example.uz/', true],
    ['http://api.uzqr.example.uz', false],
    ['https://user:pass@api.uzqr.example.uz', false],
    ['https://api.uzqr.example.uz?x=1', false],
    ['https://10.0.0.1', false],
    ['https://[::1]', false],
    ['https://localhost', false],
    ['', false],
    [null, false],
]);

it('makes no call without configuration and caps upstream calls per second', function () {
    Http::fake([Uzqr::url('invoice/status') => Uzqr::status(1, 'INV9')]);
    freshSecrets([]);

    expect(fn () => uzqrClient()->invoiceStatus('INV9'))->toThrow(fn (UzqrException $e) => expect($e->reason)->toBe(UzqrException::NOT_CONFIGURED));
    Http::assertNothingSent();

    Uzqr::configure();
    config(['uzqr.limits.upstream_per_second' => 2]);

    uzqrClient()->invoiceStatus('INV9');
    uzqrClient()->invoiceStatus('INV9');
    expect(fn () => uzqrClient()->invoiceStatus('INV9'))->toThrow(fn (UzqrException $e) => expect($e->reason)->toBe(UzqrException::BUSY));
    Http::assertSentCount(2);
});

it('parses an EMVCo tag 54 amount and refuses a different one', function () {
    $emv = '000201010212'.'5406'.'100.01'.'5303860';

    expect(UzqrQrText::emvAmount($emv))->toBe(10001)
        ->and(UzqrQrText::matchesAmount($emv, 10001))->toBeTrue()
        ->and(UzqrQrText::matchesAmount($emv, 10000))->toBeFalse()
        ->and(UzqrQrText::emvAmount('https://qr.example.uz/x'))->toBeNull()
        ->and(UzqrQrText::emvAmount('00020101'))->toBeFalse();
});
