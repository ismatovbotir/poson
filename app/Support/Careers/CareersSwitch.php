<?php

namespace App\Support\Careers;

use App\Models\Setting;
use Throwable;

/**
 * Kill switches of the application form (plan 31, CR-3): the settings rows
 * careers.applications_enabled, careers.open_applications_enabled and
 * careers.cv_enabled override the defaults in config/careers.php. Off =
 * the form (or the CV field) is hidden and the POST is refused. A settings
 * read failure falls back to the config default. Memoized in a scoped holder (CareersSwitchMemo), so a queue worker
 * re-reads after every job and Octane after every request.
 */
final class CareersSwitch
{
    public static function applicationsEnabled(): bool
    {
        return self::read('applications_enabled');
    }

    public static function openApplicationsEnabled(): bool
    {
        return self::applicationsEnabled() && self::read('open_applications_enabled');
    }

    public static function cvEnabled(): bool
    {
        return self::read('cv_enabled');
    }

    public static function forget(): void
    {
        app(CareersSwitchMemo::class)->values = [];
    }

    private static function read(string $name): bool
    {
        $memo = app(CareersSwitchMemo::class);

        if (array_key_exists($name, $memo->values)) {
            return $memo->values[$name];
        }

        $default = (bool) config("careers.{$name}", true);
        $value = $default;

        try {
            $stored = Setting::query()->where('key', (string) config("careers.settings_keys.{$name}"))->first()?->value;
            $value = is_bool($stored) ? $stored : $default;
        } catch (Throwable $exception) {
            report($exception);
        }

        $memo->values[$name] = $value;

        return $value;
    }
}
