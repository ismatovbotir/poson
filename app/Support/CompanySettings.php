<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use WeakReference;

/**
 * Company contacts and site settings: config/company.php defaults merged with
 * "settings" table overrides (a row replaces the whole value of its key).
 *
 * Registered as a scoped instance (one per request / queued job). Overrides
 * are cached for an hour under "company_settings" (a safety net against a
 * stale write racing a flush), flushed when a Setting is saved or deleted,
 * and memoized so a request reads the cache store at most once.
 */
class CompanySettings
{
    public const CACHE_KEY = 'company_settings';

    /**
     * Lifetime of the cached overrides in seconds.
     */
    public const CACHE_TTL = 3600;

    /**
     * Overrides already read during this request.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $overrides = null;

    /**
     * The live (container) instance, so flush() can reset its memo without
     * resolving a new instance.
     *
     * @var WeakReference<self>|null
     */
    protected static ?WeakReference $current = null;

    public function __construct()
    {
        static::$current = WeakReference::create($this);
    }

    /**
     * Raw (unlocalized) value; dot notation is supported ("address.ru").
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    /**
     * All settings: defaults with DB overrides applied.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace(config('company', []), $this->overrides());
    }

    /**
     * E.164, e.g. "+998901234567".
     */
    public function phone(): string
    {
        $phone = (string) $this->get('phone', '');

        return Phone::normalize($phone) ?? $phone;
    }

    /**
     * Human format, e.g. "+998 (90) 123-45-67".
     */
    public function phoneDisplay(): string
    {
        return Phone::display($this->phone());
    }

    public function email(): string
    {
        return (string) $this->get('email', '');
    }

    /**
     * Telegram link, or '' unless it is a valid https URL.
     */
    public function telegramUrl(): string
    {
        return static::safeUrl($this->get('telegram_url'));
    }

    public function address(): string
    {
        return $this->localized($this->get('address'));
    }

    public function city(): string
    {
        return $this->localized($this->get('city'));
    }

    public function workingHours(): string
    {
        return $this->localized($this->get('working_hours'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function heroStats(): array
    {
        $stats = [];

        foreach ((array) $this->get('hero_stats', []) as $stat) {
            $stats[] = [
                'value' => $this->localized($stat['value'] ?? null),
                'label' => $this->localized($stat['label'] ?? null),
            ];
        }

        return $stats;
    }

    /**
     * @return list<array{network: string, url: string}>
     */
    public function social(): array
    {
        $links = [];

        foreach ((array) $this->get('social', []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = static::safeUrl($link['url'] ?? null);

            if ($url !== '' && is_string($link['network'] ?? null)) {
                $links[] = ['network' => $link['network'], 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * The value when it is a valid https URL, otherwise '' (blocks javascript:, data:, http:).
     */
    public static function safeUrl(mixed $url): string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : '';
    }

    /**
     * Resolve a {uz, ru, en} value to the current locale, falling back to uz.
     */
    public function localized(mixed $value, ?string $locale = null): string
    {
        if (! is_array($value)) {
            return (string) ($value ?? '');
        }

        $locale ??= app()->getLocale();

        foreach (array_unique([$locale, config('app.fallback_locale'), 'uz']) as $candidate) {
            if (filled($value[$candidate] ?? null)) {
                return (string) $value[$candidate];
            }
        }

        return '';
    }

    /**
     * Forget the cached overrides and the in-memory copy of the live instance (if any).
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        static::$current?->get()?->forgetMemoized();
    }

    public function forgetMemoized(): void
    {
        $this->overrides = null;
    }

    /**
     * DB overrides keyed by setting key, memoized for the request. Without a
     * settings table (fresh install, tests that don't migrate) the error is
     * reported, the defaults are used and nothing is cached.
     *
     * @return array<string, mixed>
     */
    protected function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = Cache::remember(
                self::CACHE_KEY,
                now()->addSeconds(self::CACHE_TTL),
                fn (): array => Setting::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException $e) {
            report($e);

            return $this->overrides = [];
        }
    }
}
