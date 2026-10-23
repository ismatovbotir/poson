<?php

namespace App\Support;

use App\Enums\PhoneLabel;
use App\Enums\SocialNetwork;
use App\Models\Setting;
use App\Rules\BrandSafeText;
use App\Support\Settings\SettingsFormat;
use App\Support\Site\AiBotRules;
use App\Support\Site\LlmsTxt;
use Closure;
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
 * and memoized so a request reads the cache store at most once. The logo /
 * OG image URLs are cached the same way under "company_settings_media".
 * Integration values (group integrations) are left to App\Support\Secrets.
 *
 * Every typed getter re-validates what it reads (plan 04 §4) and returns ''
 * (or an empty list / null) for anything invalid, so a bad row or a
 * hand-edited value never reaches a page. Never holds secrets (plan 04 S2).
 */
class CompanySettings
{
    public const CACHE_KEY = 'company_settings';

    public const MEDIA_CACHE_KEY = 'company_settings_media';

    /**
     * Lifetime of the cached overrides in seconds.
     */
    public const CACHE_TTL = 3600;

    /**
     * Longest free-text value printed on the site.
     */
    public const MAX_TEXT = 255;

    /**
     * Overrides already read during this request.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $overrides = null;

    /**
     * Typed getter results for this request (reset with the overrides).
     *
     * @var array<string, mixed>
     */
    protected array $memo = [];

    /**
     * Media URLs already read during this request.
     *
     * @var array<string, string|null>|null
     */
    protected ?array $mediaUrls = null;

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
     * Raw (unlocalized, unvalidated) value; dot notation is supported ("address.ru").
     * Pages should use the typed getters instead.
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
     * Company name in the current locale, falling back to uz and then to the
     * site name from the lang files.
     */
    public function name(?string $locale = null): string
    {
        return $this->memo('name.'.($locale ?? app()->getLocale()), function () use ($locale): string {
            $name = $this->text($this->get('name'), $locale, 100);

            return $name !== '' ? $name : __('site.meta.site_name', [], $locale);
        });
    }

    /**
     * Valid phone numbers in admin order. Exactly one is primary: the flagged
     * one, else the first.
     *
     * @return list<array{label: PhoneLabel, number: string, display: string, primary: bool, site: bool}>
     */
    public function phones(): array
    {
        return $this->memo('phones', function (): array {
            $phones = [];

            foreach ((array) $this->get('phones', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $number = Phone::normalize(is_string($row['number'] ?? null) ? $row['number'] : null);

                if ($number === null) {
                    continue;
                }

                $phones[] = [
                    'label' => PhoneLabel::tryFrom((string) ($row['label'] ?? '')) ?? PhoneLabel::Office,
                    'number' => $number,
                    'display' => Phone::display($number),
                    'primary' => ($row['primary'] ?? false) === true,
                    'site' => ($row['site'] ?? true) === true,
                ];
            }

            $primaryIndex = array_search(true, array_column($phones, 'primary'), true);
            $primaryIndex = $primaryIndex === false ? 0 : $primaryIndex;

            foreach ($phones as $index => $phone) {
                $phones[$index]['primary'] = $index === $primaryIndex;
            }

            return $phones;
        });
    }

    /**
     * @return array{label: PhoneLabel, number: string, display: string, primary: bool, site: bool}|null
     */
    public function primaryPhone(): ?array
    {
        foreach ($this->phones() as $phone) {
            if ($phone['primary']) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Phones shown on the site: the primary one first (always shown), then
     * the others marked for the site.
     *
     * @return list<array{label: PhoneLabel, number: string, display: string, primary: bool, site: bool}>
     */
    public function sitePhones(): array
    {
        $phones = $this->phones();

        $primary = array_values(array_filter($phones, fn (array $phone): bool => $phone['primary']));
        $others = array_values(array_filter($phones, fn (array $phone): bool => ! $phone['primary'] && $phone['site']));

        return [...$primary, ...$others];
    }

    /**
     * Primary phone in E.164, e.g. "+998901234567"; '' when none is valid.
     */
    public function phone(): string
    {
        return $this->primaryPhone()['number'] ?? '';
    }

    /**
     * Primary phone in human format, e.g. "+998 (90) 123-45-67".
     */
    public function phoneDisplay(): string
    {
        return $this->primaryPhone()['display'] ?? '';
    }

    public function email(): string
    {
        return $this->memo('email', function (): string {
            $email = $this->get('email');

            return is_string($email) && strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
        });
    }

    /**
     * The first valid Telegram link from the social links, or ''.
     */
    public function telegramUrl(): string
    {
        foreach ($this->social() as $link) {
            if ($link['network'] === SocialNetwork::Telegram->value) {
                return $link['url'];
            }
        }

        return '';
    }

    public function address(?string $locale = null): string
    {
        return $this->memo('address.'.($locale ?? app()->getLocale()), function () use ($locale): string {
            return $this->text($this->get('address'), $locale);
        });
    }

    public function city(?string $locale = null): string
    {
        return $this->memo('city.'.($locale ?? app()->getLocale()), function () use ($locale): string {
            return $this->text($this->get('city'), $locale, 100);
        });
    }

    public function workingHours(?string $locale = null): string
    {
        return $this->memo('workingHours.'.($locale ?? app()->getLocale()), function () use ($locale): string {
            return $this->text($this->get('working_hours'), $locale, 100);
        });
    }

    /**
     * Office coordinates, or null when unset or out of range.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geo(): ?array
    {
        return $this->memo('geo', function (): ?array {
            $geo = $this->get('geo');

            if (! is_array($geo)) {
                return null;
            }

            return static::coordinates($geo['lat'] ?? null, $geo['lng'] ?? null);
        });
    }

    /**
     * Yandex Maps link to the office (built from the coordinates), or ''.
     */
    public function mapUrl(): string
    {
        $geo = $this->geo();

        return $geo === null ? '' : static::mapUrlFor($geo['lat'], $geo['lng']);
    }

    /**
     * Map link for coordinates (fixed provider template, plan 04 S9), or ''
     * when they are invalid.
     */
    public static function mapUrlFor(mixed $lat, mixed $lng): string
    {
        $geo = static::coordinates($lat, $lng);

        if ($geo === null) {
            return '';
        }

        return strtr((string) config('company.map_url_template'), [
            '{lat}' => static::formatCoordinate($geo['lat']),
            '{lng}' => static::formatCoordinate($geo['lng']),
        ]);
    }

    /**
     * Valid coordinates or null.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinates(mixed $lat, mixed $lng): ?array
    {
        $lat = SettingsFormat::match(is_float($lat) ? static::formatCoordinate($lat) : $lat, SettingsFormat::COORDINATE);
        $lng = SettingsFormat::match(is_float($lng) ? static::formatCoordinate($lng) : $lng, SettingsFormat::COORDINATE);

        if ($lat === '' || $lng === '' || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /**
     * Machine-readable opening hours (valid rows only).
     *
     * @return list<array{days: list<string>, opens: string, closes: string}>
     */
    public function openingHours(): array
    {
        return $this->memo('openingHours', function (): array {
            $rows = [];

            foreach ((array) $this->get('opening_hours', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $days = array_values(array_intersect(array_keys(SettingsFormat::DAYS), (array) ($row['days'] ?? [])));
                $opens = SettingsFormat::match($row['opens'] ?? null, SettingsFormat::TIME);
                $closes = SettingsFormat::match($row['closes'] ?? null, SettingsFormat::TIME);

                if ($days !== [] && $opens !== '' && $closes !== '' && $opens < $closes) {
                    $rows[] = ['days' => $days, 'opens' => $opens, 'closes' => $closes];
                }
            }

            return $rows;
        });
    }

    /**
     * Legal requisites; text fields localized, numbers re-validated.
     *
     * @return array{legal_name: string, inn: string, vat_code: string, bank: string, mfo: string, account: string, oked: string, director: string, legal_address: string}
     */
    public function legal(?string $locale = null): array
    {
        return $this->memo('legal.'.($locale ?? app()->getLocale()), function () use ($locale): array {
            $legal = (array) $this->get('legal', []);

            return [
                'legal_name' => $this->text($legal['legal_name'] ?? null, $locale),
                'inn' => SettingsFormat::match($legal['inn'] ?? null, SettingsFormat::INN),
                'vat_code' => SettingsFormat::match($legal['vat_code'] ?? null, SettingsFormat::VAT),
                'bank' => $this->text($legal['bank'] ?? null, $locale),
                'mfo' => SettingsFormat::match($legal['mfo'] ?? null, SettingsFormat::MFO),
                'account' => SettingsFormat::match($legal['account'] ?? null, SettingsFormat::ACCOUNT),
                'oked' => SettingsFormat::match($legal['oked'] ?? null, SettingsFormat::OKED),
                'director' => $this->text($legal['director'] ?? null, $locale),
                'legal_address' => $this->text($legal['legal_address'] ?? null, $locale),
            ];
        });
    }

    /**
     * Valid social links: known network, https on one of its hosts.
     *
     * @return list<array{network: string, url: string}>
     */
    public function social(): array
    {
        return $this->memo('social', function (): array {
            $links = [];

            foreach ((array) $this->get('social', []) as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $network = SocialNetwork::tryFrom(is_string($link['network'] ?? null) ? $link['network'] : '');
                $url = $link['url'] ?? null;

                if ($network !== null && $network->allows($url) && static::safeUrl($url) !== '') {
                    $links[] = ['network' => $network->value, 'url' => $url];
                }
            }

            return $links;
        });
    }

    /**
     * Home hero stats in the current locale (rows with an empty value or label are skipped).
     *
     * @return list<array{value: string, label: string}>
     */
    public function heroStats(): array
    {
        return $this->memo('heroStats', function (): array {
            $stats = [];

            foreach (array_slice((array) $this->get('hero_stats', []), 0, 3) as $stat) {
                if (! is_array($stat)) {
                    continue;
                }

                $value = $this->text($stat['value'] ?? null, null, 40);
                $label = $this->text($stat['label'] ?? null, null, 80);

                if ($value !== '' && $label !== '') {
                    $stats[] = ['value' => $value, 'label' => $label];
                }
            }

            return $stats;
        });
    }

    /**
     * Search engine verification codes (meta tag content values); '' when unset or invalid.
     *
     * @return array{google: string, yandex: string, bing: string}
     */
    public function verification(): array
    {
        return $this->memo('verification', function (): array {
            $codes = (array) $this->get('verification', []);
            $valid = [];

            foreach (SettingsFormat::verificationPatterns() as $engine => $pattern) {
                $valid[$engine] = SettingsFormat::match($codes[$engine] ?? null, $pattern);
            }

            /** @var array{google: string, yandex: string, bing: string} $valid */
            return $valid;
        });
    }

    /**
     * Analytics IDs. Whether the tag is rendered is decided by App\Support\Site\Analytics.
     *
     * @return array{enabled: bool, ga4: string, metrika: string}
     */
    public function tracking(): array
    {
        return $this->memo('tracking', function (): array {
            $tracking = (array) $this->get('tracking', []);

            return [
                'enabled' => ($tracking['enabled'] ?? false) === true,
                'ga4' => SettingsFormat::match($tracking['ga4'] ?? null, SettingsFormat::GA4),
                'metrika' => SettingsFormat::match($tracking['metrika'] ?? null, SettingsFormat::METRIKA),
            ];
        });
    }

    /**
     * Per-crawler robots.txt policy: every editable AI bot key => "allow" | "deny"
     * (see AiBotRules; missing or invalid values are allowed). The key holds a
     * dot, so it is read directly instead of through get().
     *
     * @return array<string, string>
     */
    public function aiBots(): array
    {
        return $this->memo('ai_bots', function (): array {
            $overrides = $this->overrides();

            return AiBotRules::normalize(array_key_exists(AiBotRules::SETTING_KEY, $overrides)
                ? $overrides[AiBotRules::SETTING_KEY]
                : config('company.'.AiBotRules::SETTING_KEY));
        });
    }

    /**
     * Whether a key was saved in admin (a settings row), as opposed to the
     * config/company.php default (placeholders until the client fills them in).
     */
    public function isCustomized(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /**
     * Uploaded logo (WebP conversion), or '' while none is ready.
     */
    public function logoUrl(): string
    {
        return $this->mediaUrls()[Setting::LOGO] ?? '';
    }

    /**
     * Default Open Graph image (JPEG 1200×630), or '' while none is ready.
     */
    public function ogImageUrl(): string
    {
        return $this->mediaUrls()[Setting::OG_IMAGE] ?? '';
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
            return is_scalar($value) ? (string) $value : '';
        }

        $locale ??= app()->getLocale();

        foreach (array_unique([$locale, config('app.fallback_locale'), 'uz']) as $candidate) {
            if (is_scalar($value[$candidate] ?? null) && filled($value[$candidate])) {
                return (string) $value[$candidate];
            }
        }

        return '';
    }

    /**
     * Forget the cached overrides and media URLs, and the in-memory copy of
     * the live instance (if any).
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::MEDIA_CACHE_KEY);

        // /llms.txt prints the contacts.
        LlmsTxt::flush();

        static::$current?->get()?->forgetMemoized();
    }

    public function forgetMemoized(): void
    {
        $this->overrides = null;
        $this->mediaUrls = null;
        $this->memo = [];
    }

    /**
     * Compute a getter once per request (and locale, where it matters).
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    protected function memo(string $key, Closure $callback): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }

    /**
     * Localized free text for the site: trimmed, single line, '' when it is
     * too long or breaks the brand rule.
     */
    protected function text(mixed $value, ?string $locale = null, int $max = self::MAX_TEXT): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $this->localized($value, $locale)));

        if ($text === '' || mb_strlen($text) > $max || BrandSafeText::violates($text)) {
            return '';
        }

        return $text;
    }

    protected static function formatCoordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
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
                fn (): array => Setting::query()->where('group', '!=', 'integrations')->whereNotIn('key', [...array_keys(Setting::MEDIA), ...array_keys(Setting::PRIVATE_MEDIA)])->pluck('value', 'key')->all(),
            );
        } catch (QueryException $e) {
            report($e);

            return $this->overrides = [];
        }
    }

    /**
     * Media key => public conversion URL (null while processing), memoized
     * and cached like the overrides. Read only when a page needs an image.
     *
     * @return array<string, string|null>
     */
    protected function mediaUrls(): array
    {
        if ($this->mediaUrls !== null) {
            return $this->mediaUrls;
        }

        try {
            return $this->mediaUrls = Cache::remember(
                self::MEDIA_CACHE_KEY,
                now()->addSeconds(self::CACHE_TTL),
                fn (): array => Setting::query()
                    ->whereIn('key', array_keys(Setting::MEDIA))
                    ->with('media')
                    ->get()
                    ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->mediaUrl()])
                    ->all(),
            );
        } catch (QueryException $e) {
            report($e);

            return $this->mediaUrls = [];
        }
    }
}
