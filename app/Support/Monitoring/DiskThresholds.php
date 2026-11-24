<?php

namespace App\Support\Monitoring;

use App\Support\CompanySettings;

/**
 * The project size limits in GB (plan 37, DM-11, DM-12): the company setting
 * "monitoring_disk" {warn_gb, critical_gb} over config('monitoring.disk').
 * Re-validated on every read: strict integers, warn 1-9,999, critical
 * warn+1-10,000. Any bad value, in either field, gives BOTH defaults, so a
 * corrupted row can never silence the alert.
 */
final class DiskThresholds
{
    public const KEY = 'monitoring_disk';

    public const WARN_MIN = 1;

    public const WARN_MAX = 9999;

    public const CRITICAL_MAX = 10000;

    private const GB = 1024 ** 3;

    public function __construct(private readonly CompanySettings $company) {}

    /**
     * Whether the pair is a valid one (strict integers).
     */
    public static function valid(mixed $warn, mixed $critical): bool
    {
        return is_int($warn) && is_int($critical)
            && $warn >= self::WARN_MIN && $warn <= self::WARN_MAX
            && $critical > $warn && $critical <= self::CRITICAL_MAX;
    }

    /**
     * @return array{warn_gb: int, critical_gb: int}
     */
    public function gb(): array
    {
        $stored = $this->company->get(self::KEY);

        if (is_array($stored) && self::valid($stored['warn_gb'] ?? null, $stored['critical_gb'] ?? null)) {
            return ['warn_gb' => $stored['warn_gb'], 'critical_gb' => $stored['critical_gb']];
        }

        return $this->defaults();
    }

    /**
     * @return array{warn_gb: int, critical_gb: int}
     */
    public function defaults(): array
    {
        $defaults = (array) config('monitoring.disk', []);

        return self::valid($defaults['warn_gb'] ?? null, $defaults['critical_gb'] ?? null)
            ? ['warn_gb' => $defaults['warn_gb'], 'critical_gb' => $defaults['critical_gb']]
            : ['warn_gb' => 5, 'critical_gb' => 8];
    }

    public function warnBytes(): int
    {
        return $this->gb()['warn_gb'] * self::GB;
    }

    public function criticalBytes(): int
    {
        return $this->gb()['critical_gb'] * self::GB;
    }
}
