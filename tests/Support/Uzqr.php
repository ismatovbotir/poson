<?php

namespace Tests\Support;

use App\Enums\PaymentMethod;
use App\Models\Order;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Dynamic UzQR test helpers (plan 11e). Fake keys and hosts only; every
 * test uses Http::preventStrayRequests() so no real bank call can happen.
 */
final class Uzqr
{
    public const PROD = 'https://api.uzqr.example.uz';

    public const TEST = 'https://sandbox.uzqr.example.uz';

    public const INN = '305123456';

    public const KEY = 'fake-uzqr-live-key-000000000001';

    public const TEST_KEY = 'fake-uzqr-test-key-000000000002';

    public const PAY_ID = 'PAY12345678901234567';

    /**
     * Configure UzQR: hosts, gate open, INN + keys as .env values, a mode.
     *
     * @param  array<string, mixed>  $env  extra / overriding config paths
     */
    public static function configure(string $mode = 'prod', array $env = [], bool $gateOpen = true, bool $testHost = true): void
    {
        config([
            'uzqr.base_urls.prod' => self::PROD,
            'uzqr.base_urls.test' => $testHost ? self::TEST : null,
            'uzqr.go_live.answered' => $gateOpen ? ['B1', 'B2', 'B3', 'B5'] : ['B1'],
            'logging.channels.payments' => ['driver' => 'single', 'path' => self::logPath(), 'level' => 'info'],
        ]);

        freshSecrets(array_merge([
            'services.uzqr.inn' => self::INN,
            'services.uzqr.api_key' => self::KEY,
            'services.uzqr.test_api_key' => self::TEST_KEY,
            'services.uzqr.mode' => $mode,
        ], $env));

        Http::preventStrayRequests();
    }

    public static function logPath(): string
    {
        return sys_get_temp_dir().'/poson-uzqr-test-'.getmypid().'.log';
    }

    public static function log(): string
    {
        return is_file(self::logPath()) ? (string) file_get_contents(self::logPath()) : '';
    }

    public static function clearLog(): void
    {
        @unlink(self::logPath());
    }

    public static function url(string $endpoint, string $mode = 'prod'): string
    {
        return ($mode === 'prod' ? self::PROD : self::TEST).'/api/v1/pos/'.$endpoint.'*';
    }

    /**
     * An unpaid order paid with dynamic UzQR (1 000 000 soʻm).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function order(array $attributes = [], bool $test = false, int $total = 1_000_000_00): Order
    {
        $factory = Order::factory()->totalling($total);

        if ($test) {
            $factory = $factory->test();
        }

        return $factory->create(['payment_method' => PaymentMethod::UzqrDynamic, ...$attributes]);
    }

    public static function created(string $invoiceId = 'INV000000001', string $qrText = 'https://qr.example.uz/pay/abc123'): PromiseInterface
    {
        return Http::response(['invoice_id' => $invoiceId, 'qr_text' => $qrText]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function status(int|string $status, string $invoiceId = 'INV000000001', array $extra = []): PromiseInterface
    {
        $paid = (int) $status === 2;

        return Http::response(array_merge([
            'invoice_id' => $invoiceId,
            'status' => $status,
            'pay_id' => $paid ? self::PAY_ID : null,
            'payer_phone' => $paid ? '+998901234567' : null,
            'card_type' => $paid ? '2' : null,
            'card_no' => $paid ? '8600********1234' : null,
            'fiscal_module' => null,
        ], $extra));
    }

    public static function error(int $code, int $http): PromiseInterface
    {
        return Http::response(['error' => ['error_code' => $code, 'message' => 'Bank text +998901234567', 'user_message' => 'Bank user text']], $http);
    }

    /**
     * @param  Response  $response
     */
    public static function authHeader(Request $request): string
    {
        return (string) ($request->header('Auth')[0] ?? '');
    }
}
