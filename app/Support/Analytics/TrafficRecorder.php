<?php

namespace App\Support\Analytics;

use App\Enums\DeviceType;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes raw analytics rows (site_visits, bot_hits) with the growth guard.
 *
 * People are identified only by a daily-salted visitor hash: the salt is
 * random per day, lives in a cache store only (config analytics.salt_store,
 * never in the database or logs) and expires 15 minutes after the Tashkent
 * day ends, so hashes can't be linked across days or reversed to an IP.
 * Crawler IPs are stored (packed) for the nightly verification only, and
 * only for crawlers that can be verified.
 *
 * A failed insert is reported (at most once a minute) and swallowed:
 * analytics never breaks a page.
 */
class TrafficRecorder
{
    /**
     * Request attributes shared by the TrackVisit and CaptureAttribution middleware.
     */
    public const ATTR_TRACKED = 'analytics.tracked';

    public const ATTR_BOT = 'analytics.bot';

    public const ATTR_SKIP = 'analytics.skip';

    public const ATTR_VISIT = 'analytics.visit';

    /**
     * Growth-guard buckets (config analytics.max_raw_per_day.*).
     */
    public const CAP_VISITS = 'visits';

    public const CAP_BOTS = 'bots';

    /**
     * Last time this process reported an insert failure (fallback when the cache is down).
     */
    protected static ?int $lastFailureReport = null;

    public function __construct(protected Attribution $attribution) {}

    /**
     * Today's date (Y-m-d) in the analytics timezone.
     */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    public static function timezone(): string
    {
        return (string) config('analytics.timezone', 'Asia/Tashkent');
    }

    /**
     * First and last moment (UTC, as stored) of a date in the analytics timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(string $date): array
    {
        $start = CarbonImmutable::parse($date, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * Record a human page view after the response (TrackVisit::terminate()).
     *
     * @param  array{channel?: string, source?: string|null, utm_medium?: string|null, utm_campaign?: string|null, referrer_host?: string|null, is_entry?: bool}|null  $visit  From CaptureAttribution; null outside the session (404 pages)
     */
    public function recordPageView(Request $request, Response $response, ?array $visit): void
    {
        if (! $this->allowRawInsert(self::CAP_VISITS)) {
            return;
        }

        if ($visit === null) {
            // No session (unmatched URL): classify this request on its own.
            $traffic = $this->attribution->classify($request);
            $utm = Attribution::sanitizeUtm($request->query());
            $visit = [
                'channel' => ($traffic->channel ?? TrafficChannel::Direct)->value,
                'source' => $traffic->source,
                'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'referrer_host' => $traffic->isInternal() ? null : TrafficClassifier::hostFromUrl($request->headers->get('referer')),
                'is_entry' => ! $traffic->isInternal(),
            ];
        }

        $this->insert('site_visits', [
            'occurred_at' => now(),
            'type' => VisitType::PageView->value,
            'visitor_hash' => $this->visitorHash($request),
            'is_entry' => (bool) ($visit['is_entry'] ?? false),
            'channel' => TrafficChannel::tryFrom((string) ($visit['channel'] ?? ''))?->value ?? TrafficChannel::Direct->value,
            'source' => $this->cut($visit['source'] ?? null, 100),
            'utm_medium' => $this->cut($visit['utm_medium'] ?? null, 100),
            'utm_campaign' => $this->cut($visit['utm_campaign'] ?? null, 150),
            'referrer_host' => $this->cut($visit['referrer_host'] ?? null, 150),
            'device' => DeviceType::fromUserAgent($request->userAgent())->value,
            'status' => $response->getStatusCode(),
            ...$this->routeContext($request),
        ]);
    }

    /**
     * Record a lead (conversion) with the session's attribution (RecordConversion).
     *
     * @param  array<string, string>  $attribution
     */
    public function recordLead(Request $request, array $attribution, string $path, ?string $locale, ?int $productId): void
    {
        $this->recordAttributed(VisitType::Lead, $request, $attribution, $path, $locale, $productId, 200, null);
    }

    /**
     * Record a "Try" click to a partner signup page with the session's
     * attribution (RecordPartnerClick). Status is the redirect's 302.
     *
     * @param  array<string, string>  $attribution
     */
    public function recordPartnerClick(Request $request, array $attribution, string $path, ?string $locale, int $productId): void
    {
        $route = $request->route();

        $this->recordAttributed(VisitType::PartnerClick, $request, $attribution, $path, $locale, $productId, 302, is_object($route) ? $route->getName() : null);
    }

    /**
     * One attributed site_visits row (lead or partner click).
     *
     * @param  array<string, string>  $attribution
     */
    protected function recordAttributed(VisitType $type, Request $request, array $attribution, string $path, ?string $locale, ?int $productId, int $status, ?string $routeName): void
    {
        if (! $this->allowRawInsert(self::CAP_VISITS)) {
            return;
        }

        $this->insert('site_visits', [
            'occurred_at' => now(),
            'type' => $type->value,
            'visitor_hash' => $this->visitorHash($request),
            'is_entry' => false,
            'channel' => Attribution::channel($attribution)->value,
            'source' => $this->cut($attribution['source'] ?? null, 100),
            'utm_medium' => $this->cut($attribution['utm_medium'] ?? null, 100),
            'utm_campaign' => $this->cut($attribution['utm_campaign'] ?? null, 150),
            'referrer_host' => $this->cut($attribution['referrer_host'] ?? null, 150),
            'route_name' => $this->cut($routeName, 100),
            'path' => $this->cut($path, 255) ?? '/',
            'locale' => $locale,
            'product_id' => $productId,
            'category_id' => null,
            'device' => DeviceType::fromUserAgent($request->userAgent())->value,
            'status' => $status,
        ]);
    }

    /**
     * Record a crawler request after the response (TrackVisit::terminate()).
     * The IP is kept only when the crawler can be verified.
     */
    public function recordBotHit(Request $request, Response $response, BotDefinition $bot): void
    {
        if (! $this->allowRawInsert(self::CAP_BOTS)) {
            return;
        }

        $context = $this->routeContext($request);
        unset($context['locale']);

        $this->insert('bot_hits', [
            'occurred_at' => now(),
            'bot' => $bot->key,
            'ip' => $bot->isVerifiable() ? IpAddress::pack($request->ip()) : null,
            'verified' => null,
            'status' => $response->getStatusCode(),
            ...$context,
        ]);
    }

    /**
     * 16 hex characters of sha256(daily salt | IP | User-Agent).
     */
    public function visitorHash(Request $request): string
    {
        return substr(hash('sha256', $this->dailySalt().'|'.$request->ip().'|'.$request->userAgent()), 0, 16);
    }

    /**
     * Growth guard: count one row of a kind against today's cap. Past the
     * cap nothing of that kind is inserted and one warning is logged for the
     * day. Increment first, then set the TTL on the first row of the day.
     */
    public function allowRawInsert(string $kind = self::CAP_VISITS): bool
    {
        $date = self::today();
        $key = "analytics:raw:{$kind}:{$date}";
        $max = (int) config("analytics.max_raw_per_day.{$kind}", 200000);
        $ttl = now()->addDays(2);

        $count = Cache::increment($key);

        if ($count === false) {
            Cache::put($key, 1, $ttl);
            $count = 1;
        } elseif ($count === 1) {
            Cache::put($key, 1, $ttl);
        }

        if ($count <= $max) {
            return true;
        }

        if (Cache::add("analytics:raw-cap-reported:{$kind}:{$date}", true, $ttl)) {
            Log::warning('Analytics: raw row cap reached, recording paused until tomorrow', [
                'kind' => $kind,
                'date' => $date,
                'max_per_day' => $max,
            ]);
        }

        return false;
    }

    /**
     * Random salt of the day, kept only in the salt cache store until 15
     * minutes after the Tashkent day ends, and never persisted elsewhere.
     */
    protected function dailySalt(): string
    {
        $store = $this->saltStore();
        $key = 'analytics:salt:'.self::today();
        $salt = $store->get($key);

        if (! is_string($salt) || $salt === '') {
            $expires = CarbonImmutable::now(self::timezone())->endOfDay()->addMinutes(15);

            if ($store->add($key, Str::random(40), $expires)) {
                $this->warnAboutPersistentSaltStore();
            }

            $salt = (string) $store->get($key);
        }

        return $salt;
    }

    protected function saltStore(): Repository
    {
        return Cache::store(config('analytics.salt_store'));
    }

    /**
     * Outside local, a database or file salt store keeps the salt on disk
     * while it is valid: warn once per new salt (once a day).
     */
    protected function warnAboutPersistentSaltStore(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $store = config('analytics.salt_store') ?: config('cache.default');
        $driver = config("cache.stores.{$store}.driver");

        if (in_array($driver, ['database', 'file'], true)) {
            Log::warning('Analytics: the visitor salt is kept in a persistent cache store; set ANALYTICS_SALT_STORE to redis or memcached', [
                'store' => $store,
                'driver' => $driver,
            ]);
        }
    }

    /**
     * Route name, path, locale and the product / category of a catalog page.
     *
     * @return array{route_name: string|null, path: string, locale: string|null, product_id: int|null, category_id: int|null}
     */
    protected function routeContext(Request $request): array
    {
        $route = $request->route();
        $routeName = is_object($route) ? $route->getName() : null;
        $locale = null;

        if (is_object($route)) {
            if (in_array('locale', $route->parameterNames(), true)) {
                $locale = app()->getLocale();
            }
        } else {
            $first = $request->segment(1);
            $locale = in_array($first, (array) config('app.supported_locales', []), true) ? $first : null;
        }

        return [
            'route_name' => $this->cut($routeName, 100),
            'path' => $this->cut('/'.ltrim($this->validUtf8($request->path()), '/'), 255) ?? '/',
            'locale' => $locale,
            'product_id' => is_object($route) ? $this->modelId($route->parameter('product')) : null,
            'category_id' => is_object($route) ? $this->modelId($route->parameter('category')) : null,
        ];
    }

    protected function modelId(mixed $parameter): ?int
    {
        return $parameter instanceof Model && is_numeric($parameter->getKey()) ? (int) $parameter->getKey() : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function insert(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
        } catch (Throwable $exception) {
            static::reportThrottled($exception);
        }
    }

    /**
     * report() at most once a minute (per cache, else per process), so a
     * broken table can't flood the error log on every request.
     */
    public static function reportThrottled(Throwable $exception): void
    {
        try {
            $first = Cache::add('analytics:failure-reported', true, 60);
        } catch (Throwable) {
            $first = static::$lastFailureReport === null || time() - static::$lastFailureReport >= 60;
        }

        if ($first) {
            static::$lastFailureReport = time();
            report($exception);
        }
    }

    protected function cut(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\p{Cc}+/u', '', $this->validUtf8($value)));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Drop invalid UTF-8 byte sequences (a raw URL path can carry any bytes).
     */
    protected function validUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
