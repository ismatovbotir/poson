<?php

namespace App\Actions\Careers;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Careers\CareersSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Save the careers kill switches (plan 31, CR-3 / CR-11): Admin only
 * (SettingPolicy::manageCareers, checked here again whatever the caller).
 * Only the three whitelisted settings keys are written, as booleans; each
 * real change gets an audit row (who/when/key/IP, never a value) in
 * SettingsArea::Careers. CareersSwitch stays the single reader.
 */
class SaveCareersSettings
{
    /**
     * @param  array<string, mixed>  $values  switch name (applications_enabled, ...) => state
     * @return int the number of switches that changed
     */
    public function handle(array $values, User $actor, ?string $ip): int
    {
        Gate::forUser($actor)->authorize('manageCareers', Setting::class);

        $changed = DB::transaction(function () use ($values, $actor, $ip): int {
            $changed = 0;

            foreach ((array) config('careers.settings_keys') as $name => $key) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }

                $value = (bool) $values[$name];
                $row = Setting::query()->where('key', $key)->lockForUpdate()->first();
                $current = $row !== null && is_bool($row->value) ? $row->value : (bool) config("careers.{$name}", true);

                if ($current === $value) {
                    continue;
                }

                if ($row === null) {
                    Setting::query()->create(['key' => $key, 'group' => SettingsArea::Careers->group(), 'value' => $value]);
                } else {
                    $row->forceFill(['group' => SettingsArea::Careers->group(), 'value' => $value])->save();
                }

                SettingsAudit::record(SettingsArea::Careers, $key, AuditAction::Updated, $actor, $ip);
                $changed++;
            }

            return $changed;
        });

        CareersSwitch::forget();

        return $changed;
    }
}
