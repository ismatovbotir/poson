<?php

namespace App\Support\Monitoring;

use App\Support\Secrets;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional external "dead man's switch" ping (plan 17, MON-S6): a GET to
 * the healthchecks.io URL after every monitoring:check. It is the only signal
 * left when the whole server is dead.
 *
 * The URL is a secret (Integrations, `monitoring.ping_url`). Whatever its
 * source, it is only requested when it is https, has no credentials, no
 * custom port and a host in config('monitoring.ping_hosts') (not editable in
 * admin). No redirects, 3 s timeouts, logs show the host and status only.
 */
final class PingUrl
{
    public static function isAllowed(?string $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 300 || preg_match('/[\s\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return $host !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && self::hostAllowed($host);
    }

    public static function hostAllowed(string $host): bool
    {
        foreach ((array) config('monitoring.ping_hosts', []) as $pattern) {
            $pattern = strtolower((string) $pattern);

            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    public static function isConfigured(): bool
    {
        return Secrets::isConfigured('monitoring.ping_url');
    }

    /**
     * Ping once. Returns false when not configured, not allowed or failed.
     */
    public static function send(): bool
    {
        $url = Secrets::get('monitoring.ping_url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! self::isAllowed($url)) {
            Log::warning('Monitoring ping URL rejected', ['host' => $host]);

            return false;
        }

        try {
            $response = Http::withoutRedirecting()->timeout(3)->connectTimeout(3)->get($url);
        } catch (Throwable) {
            Log::warning('Monitoring ping failed', ['host' => $host, 'status' => 0]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Monitoring ping failed', ['host' => $host, 'status' => $response->status()]);

            return false;
        }

        return true;
    }
}
