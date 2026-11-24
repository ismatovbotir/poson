<?php

namespace App\Actions\Monitoring;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use App\Support\Monitoring\DiskThresholds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Save the project size limits in GB (plan 37, DM-11): monitoring.manage and
 * a recent password confirmation (GuardsMonitoringWrites), strict integers
 * (warn 1-9,999, critical warn+1 to 10,000), 10 saves per 10 minutes per
 * user. Writes the "monitoring_disk" settings row and a SettingsAudit row
 * with old and new text (not secrets); the CompanySettings cache is cleared
 * after commit.
 */
final class UpdateDiskThresholds
{
    use GuardsMonitoringWrites;

    public const PER_WINDOW = 10;

    public const WINDOW = 600;

    /**
     * @throws MonitoringActionException password | rate | invalid
     */
    public function handle(?User $user, mixed $warnGb, mixed $criticalGb, ?string $ip = null): void
    {
        $this->guard($user);

        $limiter = 'monitoring:disk-limits:'.$user->id;
        $this->limit($limiter, self::PER_WINDOW);

        if (! DiskThresholds::valid($warnGb, $criticalGb)) {
            throw new MonitoringActionException('invalid');
        }

        RateLimiter::hit($limiter, self::WINDOW);

        $old = app(DiskThresholds::class)->gb();

        DB::transaction(function () use ($user, $warnGb, $criticalGb, $ip, $old): void {
            Setting::query()->updateOrCreate(
                ['key' => DiskThresholds::KEY],
                ['group' => SettingsArea::Monitoring->group(), 'value' => ['warn_gb' => $warnGb, 'critical_gb' => $criticalGb]],
            );

            SettingsAudit::recordText(
                SettingsArea::Monitoring,
                DiskThresholds::KEY,
                AuditAction::Updated,
                $user,
                $ip,
                $old['warn_gb'].'/'.$old['critical_gb'].' GB',
                $warnGb.'/'.$criticalGb.' GB',
            );

            DB::afterCommit(fn () => CompanySettings::flush());
        });
    }
}
