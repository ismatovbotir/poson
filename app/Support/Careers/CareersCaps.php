<?php

namespace App\Support\Careers;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Site-wide caps of the application form (plan 31, CR-3), config
 * careers.caps: applications per hour, CVs kept for forwarding per hour, and
 * the pending storage (files and megabytes under PENDING_DIR). Over any cap
 * the application is still saved, without the CV (text-only), and one
 * counts-only alert is sent per hour.
 */
final class CareersCaps
{
    public const PENDING_DIR = 'careers/pending';

    public const DISK = 'local';

    public const APPLICATIONS_KEY = 'careers-global-applications';

    public const CV_KEY = 'careers-global-cv';

    /**
     * Count one stored application; true while the hourly cap holds.
     */
    public static function applicationAllowed(): bool
    {
        return RateLimiter::hit(self::APPLICATIONS_KEY, 3600) <= (int) config('careers.caps.applications_per_hour', 30);
    }

    /**
     * Whether one more CV may be kept for forwarding (counts it when yes).
     */
    public static function cvAllowed(): bool
    {
        // Hit first, then compare: concurrent requests can't both slip under the cap.
        if (RateLimiter::hit(self::CV_KEY, 3600) > (int) config('careers.caps.cv_forwards_per_hour', 20)) {
            RateLimiter::decrement(self::CV_KEY);

            return false;
        }

        [$files, $bytes] = self::pendingUsage();

        if ($files >= (int) config('careers.caps.pending_files', 100)
            || $bytes >= (int) config('careers.caps.pending_megabytes', 300) * 1024 * 1024) {
            RateLimiter::decrement(self::CV_KEY);

            return false;
        }

        return true;
    }

    /**
     * Files and bytes under the pending directory (a read failure counts as full).
     *
     * @return array{0: int, 1: int}
     */
    public static function pendingUsage(): array
    {
        try {
            $disk = Storage::disk(self::DISK);
            $files = $disk->files(self::PENDING_DIR);

            return [count($files), array_sum(array_map(fn (string $path): int => (int) $disk->size($path), $files))];
        } catch (Throwable $exception) {
            report($exception);

            return [PHP_INT_MAX, PHP_INT_MAX];
        }
    }
}
