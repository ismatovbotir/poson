<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Save validated company settings of one area (a Settings → Company tab).
 *
 * In one transaction: only keys whose value really changed (compared with
 * the stored row, or the config default when there is none) are written,
 * each with an audit row (who/when/area/key/action/IP, never the value).
 * The CompanySettings cache is flushed after commit.
 *
 * Validation lives in the tab's Form object; this action owns the key
 * whitelist per area, so a tab can never write another tab's keys.
 */
class UpdateCompanySettings
{
    /**
     * Keys each area may write.
     */
    public const KEYS = [
        'contacts' => ['name', 'phones', 'email', 'address', 'city', 'geo', 'working_hours', 'opening_hours'],
        'legal' => ['legal'],
        'social' => ['social'],
        'hero' => ['hero_stats'],
        'seo' => ['verification'],
        'tracking' => ['tracking'],
    ];

    /**
     * @param  array<string, mixed>  $values  key => new value
     * @return list<string> the keys that changed
     *
     * @throws InvalidArgumentException for a key outside the area
     */
    public function handle(SettingsArea $area, array $values, ?User $user, ?string $ip): array
    {
        $allowed = self::KEYS[$area->value] ?? [];
        $unknown = array_diff(array_keys($values), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown settings keys for '.$area->value.': '.implode(', ', $unknown));
        }

        $changed = DB::transaction(function () use ($area, $values, $user, $ip): array {
            $rows = Setting::query()->whereIn('key', array_keys($values))->lockForUpdate()->get()->keyBy('key');
            $changed = [];

            foreach ($values as $key => $value) {
                $current = $rows->has($key) ? $rows[$key]->value : config("company.{$key}");

                if (static::canonical($current) === static::canonical($value)) {
                    continue;
                }

                Setting::query()->updateOrCreate(['key' => $key], ['group' => $area->group(), 'value' => $value]);
                SettingsAudit::record($area, $key, static::isEmpty($value) ? AuditAction::Cleared : AuditAction::Updated, $user, $ip);

                $changed[] = $key;
            }

            if ($changed !== []) {
                DB::afterCommit(fn () => CompanySettings::flush());
            }

            return $changed;
        });

        return $changed;
    }

    /**
     * Comparable form: associative arrays sorted by key, lists kept in order.
     */
    public static function canonical(mixed $value): string
    {
        return json_encode(static::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(static::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Null, '' or an array holding only empty values.
     */
    private static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! static::isEmpty($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || $value === '' || $value === false;
    }
}
