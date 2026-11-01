<?php

namespace App\Services\Payments\Uzqr;

use App\Enums\PaymentMode;
use App\Enums\UzqrTransactionStatus;
use App\Models\UzqrTransaction;
use App\Support\Orders\PaymentOptions;
use App\Support\Secrets;
use Illuminate\Support\Facades\Cache;

/**
 * Effective dynamic UzQR configuration (plan 11e §3, security conditions
 * 32–34, 36, 44, 53). Secrets only through App\Support\Secrets; base URLs
 * only from config/uzqr.php (.env).
 *
 * - baseUrl(mode): https, a host name (no IP literal), no user info, query
 *   or fragment; otherwise null (no calls);
 * - testAvailable(): a valid test host that differs from the prod host.
 *   Without it the site is forced to prod and the test option is hidden;
 * - activeMode(): the uzqr.mode setting, forced to prod without a test host;
 * - goLiveOpen(): the bank answered B1, B2, B3 and B5 (UZQR_BANK_ANSWERS);
 * - ready(mode): INN + key (+ gate for prod, distinct test key for test) +
 *   base URL + the UzqrHold registered;
 * - canStart(mode): ready, the active mode, no auth-failed flag;
 * - auth-failed flag per mode (15 min), cleared by a successful call or an
 *   Admin connection test.
 */
class UzqrConfig
{
    public const AUTH_FAILED_KEY = 'uzqr:auth_failed:';

    public function inn(): ?string
    {
        $inn = Secrets::get('uzqr.inn');

        return UzqrAuth::validInn($inn) ? $inn : null;
    }

    /**
     * The API key of a mode, or null. Never log or store the return value.
     */
    public function apiKey(PaymentMode $mode): ?string
    {
        $key = Secrets::get($mode === PaymentMode::Prod ? 'uzqr.api_key' : 'uzqr.test_api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function baseUrl(PaymentMode $mode): ?string
    {
        return static::validBaseUrl(config('uzqr.base_urls.'.$mode->value));
    }

    public static function validBaseUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '' || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            return null;
        }

        $host = trim(strtolower($parts['host']), '[]');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) !== 1) {
            return null;
        }

        return $url;
    }

    public function host(PaymentMode $mode): ?string
    {
        $url = $this->baseUrl($mode);

        return $url !== null ? strtolower((string) parse_url($url, PHP_URL_HOST)) : null;
    }

    public function testAvailable(): bool
    {
        $test = $this->host(PaymentMode::Test);

        return $test !== null && $test !== $this->host(PaymentMode::Prod);
    }

    public function activeMode(): PaymentMode
    {
        if (! $this->testAvailable()) {
            return PaymentMode::Prod;
        }

        return Secrets::get('uzqr.mode') === PaymentMode::Prod->value ? PaymentMode::Prod : PaymentMode::Test;
    }

    /**
     * @return list<string> required bank answers still missing
     */
    public function missingBankAnswers(): array
    {
        $answered = (array) config('uzqr.go_live.answered', []);

        return array_values(array_diff((array) config('uzqr.go_live.required', []), $answered));
    }

    public function goLiveOpen(): bool
    {
        return $this->missingBankAnswers() === [];
    }

    /**
     * The test key is set and differs from the live key (condition 34).
     */
    public function testKeyDistinct(): bool
    {
        $test = $this->apiKey(PaymentMode::Test);

        return $test !== null && ! hash_equals((string) $this->apiKey(PaymentMode::Prod), $test);
    }

    /**
     * Credentials and base URL to call the bank in a mode (also for polling
     * rows of a mode that is no longer active, condition 36).
     */
    public function callable(PaymentMode $mode): bool
    {
        return $this->inn() !== null && $this->apiKey($mode) !== null && $this->baseUrl($mode) !== null
            && ($mode === PaymentMode::Prod || $this->testAvailable());
    }

    /**
     * New invoices may be created in the mode (configuration only).
     */
    public function ready(PaymentMode $mode): bool
    {
        if (! PaymentOptions::hasHold(UzqrTransaction::PROVIDER) || ! $this->callable($mode)) {
            return false;
        }

        return $mode === PaymentMode::Prod ? $this->goLiveOpen() : $this->testKeyDistinct();
    }

    public function canStart(PaymentMode $mode): bool
    {
        return $this->activeMode() === $mode && $this->ready($mode) && ! $this->authFailed($mode);
    }

    public function authFailed(PaymentMode $mode): bool
    {
        return Cache::has(self::AUTH_FAILED_KEY.$mode->value);
    }

    public function flagAuthFailed(PaymentMode $mode): void
    {
        Cache::put(self::AUTH_FAILED_KEY.$mode->value, true, now()->addMinutes((int) config('uzqr.auth_failed_minutes', 15)));
    }

    public function clearAuthFailed(PaymentMode $mode): void
    {
        Cache::forget(self::AUTH_FAILED_KEY.$mode->value);
    }

    /**
     * Invoices open right now on all orders (global cap, condition 44).
     */
    public function openCount(): int
    {
        return UzqrTransaction::query()->whereIn('status', [UzqrTransactionStatus::Creating->value, UzqrTransactionStatus::Pending->value])->count();
    }

    public function openCapReached(): bool
    {
        return $this->openCount() >= (int) config('checkout.uzqr_max_open', 200);
    }
}
