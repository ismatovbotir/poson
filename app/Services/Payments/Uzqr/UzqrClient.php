<?php

namespace App\Services\Payments\Uzqr;

use App\Enums\PaymentMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * HTTP client of the UzQR POS API 1.2 for one mode (plan 11e §3, security
 * conditions 31, 44, 48, 49):
 *
 * - base URL from config only (UzqrConfig), redirects off, TLS ≥ 1.2;
 * - a fresh Auth header per request, never logged or kept;
 * - timeouts: interactive connect 3 s / total 5 s, background 5 s / 20 s;
 * - at most N upstream calls per second for the whole site (else "busy");
 * - strict response validation (≤ 16 KB JSON, ids, status, pay_id, card);
 * - failures become UzqrException with our own code, never a
 *   RequestException; responses are never ->throw()n;
 * - "payments" log: endpoint, mode, HTTP code, error_code, our code,
 *   duration and ids — never a body, header, key or qr_text.
 */
class UzqrClient
{
    public const MAX_BODY = 16_384;

    public const ID = '/^[A-Za-z0-9]{1,25}$/';

    public const PAY_ID = '/^[A-Za-z0-9]{20}$/';

    public const CARD_NO = '/^\d{4}\*{8}(\d{4})$/';

    public const UPSTREAM_KEY = 'uzqr-upstream';

    public function __construct(
        protected UzqrConfig $config,
        protected PaymentMode $mode = PaymentMode::Prod,
        protected bool $interactive = false,
    ) {}

    public function forMode(PaymentMode $mode, bool $interactive = false): static
    {
        return new static($this->config, $mode, $interactive);
    }

    public function mode(): PaymentMode
    {
        return $this->mode;
    }

    /**
     * invoice/create; retried in the request on connection / 5xx errors with
     * the same external_id (the bank answers a repeat with the same invoice).
     * Interactive (the customer is waiting): one retry at most and none
     * after a timeout (change review L2); the next open / uzqr:sync retries.
     *
     * @throws UzqrException
     */
    public function createInvoice(string $externalId, int $amountTiyin, int $retries = 2): UzqrInvoice
    {
        $attempt = 0;
        $retries = $this->interactive ? min($retries, 1) : $retries;

        while (true) {
            try {
                return $this->tryCreate($externalId, $amountTiyin);
            } catch (UzqrException $exception) {
                if (! $exception->isRetryable() || $attempt >= $retries || ($this->interactive && $exception->reason === UzqrException::TIMEOUT)) {
                    throw $exception;
                }

                $attempt++;
                usleep(200_000);
            }
        }
    }

    /**
     * invoice/status (never retried here).
     *
     * @throws UzqrException
     */
    public function invoiceStatus(string $invoiceId): UzqrInvoiceStatus
    {
        $endpoint = 'invoice/status';
        $data = $this->json($endpoint, $this->send($endpoint, 'GET', ['invoice_id' => $invoiceId], ['invoice_id' => $invoiceId]));

        $id = $data['invoice_id'] ?? null;

        if (! is_string($id) || preg_match(self::ID, $id) !== 1 || ! hash_equals($invoiceId, $id)) {
            throw new UzqrException(UzqrException::INVALID_RESPONSE, $endpoint, 200);
        }

        $status = $this->intValue($data['status'] ?? null);

        if (! in_array($status, [UzqrInvoiceStatus::IN_PROGRESS, UzqrInvoiceStatus::PAID, UzqrInvoiceStatus::REJECTED], true)) {
            throw new UzqrException(UzqrException::INVALID_RESPONSE, $endpoint, 200);
        }

        if ($status !== UzqrInvoiceStatus::PAID) {
            return new UzqrInvoiceStatus($id, $status);
        }

        $payId = $data['pay_id'] ?? null;

        if (! is_string($payId) || preg_match(self::PAY_ID, $payId) !== 1) {
            throw new UzqrException(UzqrException::MISSING_PAY_ID, $endpoint, 200);
        }

        $cardType = $this->intValue($data['card_type'] ?? null);
        $cardNo = $data['card_no'] ?? null;

        // payer_phone is never read (condition 49).
        return new UzqrInvoiceStatus(
            $id,
            $status,
            $payId,
            in_array($cardType, [1, 2, 3], true) ? $cardType : null,
            is_string($cardNo) && preg_match(self::CARD_NO, $cardNo, $matches) === 1 ? $matches[1] : null,
        );
    }

    /**
     * invoice/cancel: 200 with an empty body; 3002 (already paid) and 3001
     * (not found) arrive as UzqrException.
     *
     * @throws UzqrException
     */
    public function cancelInvoice(string $invoiceId): void
    {
        $endpoint = 'invoice/cancel';
        $response = $this->send($endpoint, 'POST', ['invoice_id' => $invoiceId], ['invoice_id' => $invoiceId]);

        if (! $response->successful()) {
            throw $this->failure($endpoint, $response);
        }
    }

    /**
     * Connection test (bank doc §2.4.4): invoice/create with empty
     * parameters; 400 means the credentials were accepted.
     *
     * @throws UzqrException
     */
    public function ping(): void
    {
        $endpoint = 'invoice/create';
        $response = $this->send($endpoint, 'POST', [], ['ping' => true]);

        if ($response->status() === 400) {
            return;
        }

        throw $response->successful()
            ? new UzqrException(UzqrException::UNEXPECTED, $endpoint, $response->status())
            : $this->failure($endpoint, $response);
    }

    /**
     * @throws UzqrException
     */
    protected function tryCreate(string $externalId, int $amountTiyin): UzqrInvoice
    {
        $endpoint = 'invoice/create';
        $data = $this->json($endpoint, $this->send($endpoint, 'POST', [
            'external_id' => $externalId,
            'amount' => UzqrAmount::format($amountTiyin),
        ], ['external_id' => $externalId]));

        $id = $data['invoice_id'] ?? null;
        $qrText = $data['qr_text'] ?? null;

        if (! is_string($id) || preg_match(self::ID, $id) !== 1 || ! UzqrQrText::isValid($qrText)) {
            throw new UzqrException(UzqrException::INVALID_RESPONSE, $endpoint, 200);
        }

        return new UzqrInvoice($id, $qrText);
    }

    /**
     * @param  array<string, mixed>  $payload  JSON body (POST) or query (GET)
     * @param  array<string, mixed>  $context  ids for the log
     *
     * @throws UzqrException
     */
    protected function send(string $endpoint, string $method, array $payload, array $context): Response
    {
        $base = $this->config->baseUrl($this->mode);
        $inn = $this->config->inn();
        $key = $this->config->apiKey($this->mode);

        if ($base === null || $inn === null || $key === null || ! $this->config->callable($this->mode)) {
            throw new UzqrException(UzqrException::NOT_CONFIGURED, $endpoint);
        }

        $perSecond = (int) config('uzqr.limits.upstream_per_second', 5);

        if (RateLimiter::tooManyAttempts(self::UPSTREAM_KEY, $perSecond)) {
            $this->log($endpoint, 0, null, UzqrException::BUSY, 0, $context);

            throw new UzqrException(UzqrException::BUSY, $endpoint);
        }

        RateLimiter::hit(self::UPSTREAM_KEY, 1);

        $started = hrtime(true);

        try {
            $request = $this->request($base, $inn, $key);
            unset($key);

            $response = $method === 'GET'
                ? $request->get('/api/v1/pos/'.$endpoint, $payload)
                : $request->post('/api/v1/pos/'.$endpoint, $payload === [] ? (object) [] : $payload);
        } catch (ConnectionException $exception) {
            $reason = str_contains(strtolower($exception->getMessage()), 'timed out') || str_contains($exception->getMessage(), 'cURL error 28')
                ? UzqrException::TIMEOUT
                : UzqrException::CONNECTION_FAILED;
            $this->log($endpoint, 0, null, $reason, $started, $context);

            throw new UzqrException($reason, $endpoint);
        } catch (Throwable) {
            // Never pass the original exception on: its message may hold the URL or body.
            $this->log($endpoint, 0, null, UzqrException::CONNECTION_FAILED, $started, $context);

            throw new UzqrException(UzqrException::CONNECTION_FAILED, $endpoint);
        }

        $errorCode = $response->successful() ? null : $this->errorCode($response);
        $code = $response->successful() || ($endpoint === 'invoice/create' && ($context['ping'] ?? false) && $response->status() === 400)
            ? 'ok'
            : UzqrException::fromResponse($endpoint, $response->status(), $errorCode)->reason;

        $this->log($endpoint, $response->status(), $errorCode, $code, $started, $context);

        return $response;
    }

    protected function request(string $base, string $inn, #[\SensitiveParameter] string $key): PendingRequest
    {
        $timeouts = (array) config('uzqr.timeouts');

        return Http::baseUrl($base)
            ->withHeaders([UzqrAuth::HEADER => UzqrAuth::header($inn, $key)])
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->withOptions(['allow_redirects' => false, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT])
            ->connectTimeout((int) ($this->interactive ? ($timeouts['interactive_connect'] ?? 3) : ($timeouts['background_connect'] ?? 5)))
            ->timeout((int) ($this->interactive ? ($timeouts['interactive'] ?? 5) : ($timeouts['background'] ?? 20)));
    }

    /**
     * The decoded JSON object of a successful response.
     *
     * @return array<string, mixed>
     *
     * @throws UzqrException
     */
    protected function json(string $endpoint, Response $response): array
    {
        if (! $response->successful()) {
            throw $this->failure($endpoint, $response);
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BODY) {
            throw new UzqrException(UzqrException::INVALID_RESPONSE, $endpoint, $response->status());
        }

        $data = json_decode($body, true);

        if (! is_array($data) || array_is_list($data) || isset($data['error'])) {
            throw new UzqrException(UzqrException::INVALID_RESPONSE, $endpoint, $response->status());
        }

        return $data;
    }

    protected function failure(string $endpoint, Response $response): UzqrException
    {
        return UzqrException::fromResponse($endpoint, $response->status(), $this->errorCode($response));
    }

    protected function errorCode(Response $response): ?int
    {
        if (strlen($response->body()) > self::MAX_BODY) {
            return null;
        }

        $data = json_decode($response->body(), true);
        $code = is_array($data) && isset($data['error']) && is_array($data['error']) ? ($data['error']['error_code'] ?? null) : null;

        return $this->intValue($code);
    }

    protected function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $endpoint, int $httpStatus, ?int $errorCode, string $code, int $started, array $context): void
    {
        Log::channel('payments')->info('uzqr', [
            'endpoint' => $endpoint,
            'mode' => $this->mode->value,
            'http' => $httpStatus,
            'error_code' => $errorCode,
            'code' => $code,
            'ms' => $started > 0 ? intdiv(hrtime(true) - $started, 1_000_000) : 0,
            ...array_intersect_key($context, array_flip(['external_id', 'invoice_id', 'transaction_id', 'ping'])),
        ]);
    }
}
