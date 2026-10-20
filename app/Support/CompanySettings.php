<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Company contacts and site settings: config/company.php defaults merged with
 * "settings" table overrides (a row replaces the whole value of its key).
 *
 * Registered as a singleton. Overrides are cached forever under "settings"
 * and flushed when a Setting is saved or deleted.
 */
class CompanySettings
{
    public const CACHE_KEY = 'settings';

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

    public function telegramUrl(): string
    {
        return (string) $this->get('telegram_url', '');
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
        return array_values((array) $this->get('social', []));
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

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * DB overrides keyed by setting key. Without a settings table (fresh install,
     * tests that don't migrate) the defaults are used and nothing is cached.
     *
     * @return array<string, mixed>
     */
    protected function overrides(): array
    {
        try {
            return Cache::rememberForever(
                self::CACHE_KEY,
                fn (): array => Setting::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException) {
            return [];
        }
    }
}
