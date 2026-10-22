<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * First-touch attribution of a human session (plan 04 §8), stored in the
 * session under "attribution" by the first tracked page view:
 *
 *   channel, source, utm_source…utm_content, referrer_host, landing
 *
 * Replaces the old session "utm" array of the lead form. Values from the
 * session are re-sanitized on every read.
 */
class Attribution
{
    public const SESSION_KEY = 'attribution';

    public const UTM_MAX_LENGTH = 255;

    /**
     * @var list<string>
     */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(protected TrafficClassifier $classifier) {}

    /**
     * Attribution stored in the session, or null when this session has none yet.
     *
     * @return array<string, string>|null
     */
    public function stored(Request $request): ?array
    {
        $stored = $this->session($request)?->get(self::SESSION_KEY);

        return is_array($stored) ? static::sanitize($stored) : null;
    }

    /**
     * The session's attribution; when it has none yet, the attribution of
     * this request, remembered in the session (first touch).
     *
     * @return array<string, string>
     */
    public function current(Request $request): array
    {
        $stored = $this->stored($request);

        if ($stored !== null) {
            return $stored;
        }

        $attribution = $this->fromRequest($request);

        $this->session($request)?->put(self::SESSION_KEY, $attribution);

        return $attribution;
    }

    /**
     * The request's session, else the application's session store (the
     * same store StartSession uses; Livewire component tests have no
     * session on the request).
     */
    protected function session(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        return app()->bound('session.store') ? app('session.store') : null;
    }

    /**
     * Attribution of one request: UTM tags, referrer and landing path.
     * Internal navigation (own referrer) counts as direct for a new session.
     *
     * @return array<string, string>
     */
    public function fromRequest(Request $request): array
    {
        $traffic = $this->classify($request);
        $utm = static::sanitizeUtm($request->query());
        $referrerHost = TrafficClassifier::hostFromUrl($request->headers->get('referer'));

        return array_filter([
            'channel' => ($traffic->channel ?? TrafficChannel::Direct)->value,
            'source' => $traffic->source,
            ...$utm,
            'referrer_host' => $traffic->isInternal() ? null : $referrerHost,
            'landing' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Channel and source of this request alone (internal when the referrer is the site itself).
     */
    public function classify(Request $request): TrafficSource
    {
        $utm = static::sanitizeUtm($request->query());

        return $this->classifier->classify(
            $utm['utm_source'] ?? null,
            TrafficClassifier::hostFromUrl($request->headers->get('referer')),
            static::ownHosts($request),
        );
    }

    /**
     * The site's own hosts: APP_URL's host, its www twin and the request host.
     *
     * @return list<string>
     */
    public static function ownHosts(?Request $request = null): array
    {
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST), parse_url(URL::to('/'), PHP_URL_HOST)];

        if ($request !== null) {
            $hosts[] = $request->getHost();
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $host): ?string => is_string($host) ? TrafficClassifier::normalizeHost($host) : null, $hosts),
        )));
    }

    /**
     * Channel stored in an attribution array (direct when missing or unknown).
     *
     * @param  array<string, string>  $attribution
     */
    public static function channel(array $attribution): TrafficChannel
    {
        return TrafficChannel::tryFrom($attribution['channel'] ?? '') ?? TrafficChannel::Direct;
    }

    /**
     * Keep known keys with string values; the UTM tags are sanitized like sanitizeUtm().
     *
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    public static function sanitize(array $input): array
    {
        $clean = [];
        $channel = TrafficChannel::tryFrom(is_string($input['channel'] ?? null) ? $input['channel'] : '');

        if ($channel !== null) {
            $clean['channel'] = $channel->value;
        }

        foreach (['source' => TrafficClassifier::SOURCE_MAX_LENGTH, 'referrer_host' => 150, 'landing' => 255] as $key => $max) {
            $value = static::cleanString($input[$key] ?? null, $max);

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return [...$clean, ...static::sanitizeUtm($input)];
    }

    /**
     * Keep whitelisted UTM keys with non-empty string values; strip control
     * characters and cut each value to UTM_MAX_LENGTH characters.
     *
     * @return array<string, string>
     */
    public static function sanitizeUtm(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $utm = [];

        foreach (self::UTM_KEYS as $key) {
            $value = static::cleanString($input[$key] ?? null, self::UTM_MAX_LENGTH);

            if ($value !== null) {
                $utm[$key] = $value;
            }
        }

        return $utm;
    }

    /**
     * Only the UTM tags of an attribution array.
     *
     * @param  array<string, string>  $attribution
     * @return array<string, string>
     */
    public static function utm(array $attribution): array
    {
        return array_intersect_key($attribution, array_flip(self::UTM_KEYS));
    }

    protected static function cleanString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\p{Cc}+/u', '', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
