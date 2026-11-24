<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Turn storing Yandex ad click ids on or off (plan 40 §11, YD-8; Admin →
 * Analytics → Campaigns). Admin only: analytics.export, checked here too,
 * not only in the screen; the screen also requires a recent password
 * confirmation. Off (the default) = no yclid / ClientID is stored and the
 * offline-conversion CSV is disabled.
 *
 * In one transaction (row locked): nothing happens when unchanged;
 * otherwise the analytics.ad_click_ids_enabled row (group seo) is written
 * as a boolean and audited (area analytics, action enabled / disabled,
 * never more). CompanySettings is flushed after commit.
 */
class UpdateAdClickIds
{
    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     */
    public function handle(bool $enabled, User $user, ?string $ip): bool
    {
        if (! $user->can('analytics.export')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($enabled, $user, $ip): bool {
            $row = Setting::query()->where('key', CompanySettings::AD_CLICK_IDS_KEY)->lockForUpdate()->first();

            if (($row?->value === true) === $enabled) {
                return false;
            }

            Setting::query()->updateOrCreate(
                ['key' => CompanySettings::AD_CLICK_IDS_KEY],
                ['group' => SettingsArea::Analytics->group(), 'value' => $enabled],
            );

            SettingsAudit::record(SettingsArea::Analytics, CompanySettings::AD_CLICK_IDS_KEY, $enabled ? AuditAction::Enabled : AuditAction::Disabled, $user, $ip);

            DB::afterCommit(fn () => CompanySettings::flush());

            return true;
        });
    }
}
