<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;
use Illuminate\Support\Str;

/**
 * Decides the channel of a human visit (plan 04 §8):
 *
 *  1. utm_source: an AI assistant (utm_source=chatgpt.com, …) → ai,
 *     anything else → campaign;
 *  2. referrer host: none → direct, own host → internal, AI hosts → ai,
 *     (and its subdomains), search engines → search, social networks →
 *     social, else referral.
 *
 * Host lists are in config('analytics.hosts'). Pure: no request access.
 */
class TrafficClassifier
{
    public const SOURCE_MAX_LENGTH = 100;

    /**
     * Words in a utm_source that mean an AI assistant (when it isn't a host).
     *
     * @var array<string, string> word => canonical source
     */
    public const AI_SOURCE_WORDS = [
        'chatgpt' => 'chatgpt.com',
        'openai' => 'chatgpt.com',
        'perplexity' => 'perplexity.ai',
        'copilot' => 'copilot.microsoft.com',
        'gemini' => 'gemini.google.com',
        'claude' => 'claude.ai',
        'deepseek' => 'chat.deepseek.com',
        'grok' => 'grok.com',
    ];

    /**
     * @param  string|null  $utmSource  Sanitized utm_source, if any
     * @param  string|null  $referrerHost  Host of the Referer header, if any
     * @param  list<string>  $ownHosts  The site's own hosts (internal navigation)
     */
    public function classify(?string $utmSource, ?string $referrerHost, array $ownHosts = []): TrafficSource
    {
        $utmSource = Str::lower(trim((string) $utmSource));

        if ($utmSource !== '') {
            $aiSource = $this->aiSourceFromUtm($utmSource);

            return $aiSource !== null
                ? new TrafficSource(TrafficChannel::Ai, $aiSource)
                : new TrafficSource(TrafficChannel::Campaign, mb_substr($utmSource, 0, self::SOURCE_MAX_LENGTH));
        }

        $host = self::normalizeHost($referrerHost);

        if ($host === null) {
            return new TrafficSource(TrafficChannel::Direct);
        }

        foreach ($ownHosts as $ownHost) {
            $ownHost = self::normalizeHost($ownHost);

            // The site itself and its subdomains (shop.poson.uz) are internal.
            if ($ownHost !== null && ($host === $ownHost || str_ends_with($host, '.'.$ownHost))) {
                return TrafficSource::internal();
            }
        }

        foreach (['ai' => TrafficChannel::Ai, 'search' => TrafficChannel::Search, 'social' => TrafficChannel::Social] as $group => $channel) {
            foreach ((array) config("analytics.hosts.{$group}", []) as $pattern) {
                if (self::hostMatches($host, (string) $pattern)) {
                    return new TrafficSource($channel, $host);
                }
            }
        }

        return new TrafficSource(TrafficChannel::Referral, $host);
    }

    /**
     * Lowercase host without "www.", port or trailing dot; null when empty or invalid.
     */
    public static function normalizeHost(?string $host): ?string
    {
        $host = Str::lower(trim((string) $host));
        $host = rtrim((string) preg_replace('/:\d+$/', '', $host), '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        return mb_substr($host, 0, 150);
    }

    /**
     * Host of a URL (Referer header), normalized; null when missing or not http(s).
     */
    public static function hostFromUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(Str::lower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        return self::normalizeHost($parts['host'] ?? null);
    }

    /**
     * "example.com" matches the host and its subdomains; "google.*" matches any TLD
     * (google.com, google.co.uz) and subdomains of those.
     */
    public static function hostMatches(string $host, string $pattern): bool
    {
        if (str_ends_with($pattern, '.*')) {
            $name = preg_quote(substr($pattern, 0, -2), '/');

            return preg_match('/(^|\.)'.$name.'\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) === 1;
        }

        return $host === $pattern || str_ends_with($host, '.'.$pattern);
    }

    protected function aiSourceFromUtm(string $utmSource): ?string
    {
        $asHost = self::normalizeHost($utmSource);

        if ($asHost !== null) {
            foreach ((array) config('analytics.hosts.ai', []) as $pattern) {
                if (self::hostMatches($asHost, (string) $pattern)) {
                    return $asHost;
                }
            }
        }

        foreach (self::AI_SOURCE_WORDS as $word => $source) {
            if (preg_match('/(^|[^a-z])'.$word.'([^a-z]|$)/', $utmSource) === 1) {
                return $source;
            }
        }

        return null;
    }
}
