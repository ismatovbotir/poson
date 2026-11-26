<?php

namespace App\Actions\Office;

use App\Enums\AiUsageChannel;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Chat\AiSwitch;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Save Virtual office → Settings (plan 41 §8.5, office.settings = Admin
 * only, VO-1, VO-10, VO-11). Validated here:
 *
 *  - ingest / groups (plan 44) / AI / auto-lead switches; AI on needs the API key;
 *  - debounce 5–60 minutes, per-chat cap 1–24;
 *  - caps per office channel 0 … cap_factor × default.
 * Every changed key is audited without its value. The owner allow-list is
 * not saved here: SetOfficeOwners (plan 44 §1, OG-3) owns it; a submitted
 * owner_ids value is ignored. Every save still recomputes each stored
 * connection's is_allowed (a lock set or dropped in .env since).
 */
class SaveOfficeSettings
{
    /**
     * @param  array<string, mixed>  $data
     * @return int Keys that changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ?string $ip = null): int
    {
        if (! $actor->can('office.settings')) {
            throw new AuthorizationException;
        }

        $valid = Validator::make($data, $this->rules())->validate();

        if ((bool) $valid['ai_enabled'] && ! OfficeSettings::aiStored() && ! AiSwitch::keyConfigured()) {
            throw ValidationException::withMessages(['ai_enabled' => __('office.errors.key_missing')]);
        }

        $caps = [];

        foreach (AiUsageChannel::office() as $channel) {
            $caps[$channel->value] = [
                'requests' => (int) $valid['caps'][$channel->value]['requests'],
                'tokens' => (int) $valid['caps'][$channel->value]['tokens'],
            ];
        }

        $values = [
            OfficeSettings::INGEST => (bool) $valid['ingest_enabled'],
            OfficeSettings::AI => (bool) $valid['ai_enabled'],
            OfficeSettings::AUTO_LEAD => (bool) $valid['auto_lead'],
            OfficeSettings::DEBOUNCE => (int) $valid['debounce_minutes'],
            OfficeSettings::PER_CHAT => (int) $valid['per_chat_daily'],
            OfficeSettings::CAPS => $caps,
        ];

        // Plan 44: the Telegram groups switch (Admin only, default off); a caller that does not send it leaves it alone.
        if (array_key_exists('groups_enabled', $valid)) {
            $values[OfficeSettings::GROUPS] = (bool) $valid['groups_enabled'];
        }

        $changed = DB::transaction(function () use ($values, $actor, $ip): int {
            $changed = 0;

            foreach ($values as $key => $value) {
                $row = Setting::query()->where('key', $key)->lockForUpdate()->first();

                if ($row !== null && $row->value === $value) {
                    continue;
                }

                if ($row === null) {
                    Setting::query()->create(['key' => $key, 'group' => SettingsArea::Office->group(), 'value' => $value]);
                } else {
                    $row->forceFill(['group' => SettingsArea::Office->group(), 'value' => $value])->save();
                }

                if (is_bool($value)) {
                    SettingsAudit::record(SettingsArea::Office, $key, $value ? AuditAction::Enabled : AuditAction::Disabled, $actor, $ip);
                } else {
                    SettingsAudit::record(SettingsArea::Office, $key, AuditAction::Updated, $actor, $ip);
                }

                $changed++;
            }

            return $changed;
        });

        OfficeSettings::forget();
        // Every successful save: connections stored before a .env lock change get their flag again (VO-1).
        UpsertBusinessConnection::recomputeAllowed();

        return $changed;
    }

    /**
     * Switch office ingest off (wipe-all, VO-12), audited.
     */
    public static function disableIngest(?User $actor, ?string $ip): void
    {
        $row = Setting::query()->where('key', OfficeSettings::INGEST)->lockForUpdate()->first();

        if ($row === null) {
            Setting::query()->create(['key' => OfficeSettings::INGEST, 'group' => SettingsArea::Office->group(), 'value' => false]);
        } elseif ($row->value !== false) {
            $row->forceFill(['value' => false])->save();
            SettingsAudit::record(SettingsArea::Office, OfficeSettings::INGEST, AuditAction::Disabled, $actor, $ip);
        }

        OfficeSettings::forget();
    }

    /**
     * Switch the Telegram groups ingest off (wipe-all, plan 44 OG-12), audited.
     */
    public static function disableGroups(?User $actor, ?string $ip): void
    {
        $row = Setting::query()->where('key', OfficeSettings::GROUPS)->lockForUpdate()->first();

        if ($row === null) {
            Setting::query()->create(['key' => OfficeSettings::GROUPS, 'group' => SettingsArea::Office->group(), 'value' => false]);
        } elseif ($row->value !== false) {
            $row->forceFill(['value' => false])->save();
            SettingsAudit::record(SettingsArea::Office, OfficeSettings::GROUPS, AuditAction::Disabled, $actor, $ip);
        }

        OfficeSettings::forget();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'ingest_enabled' => ['required', 'boolean'],
            'groups_enabled' => ['sometimes', 'boolean'],
            'ai_enabled' => ['required', 'boolean'],
            'auto_lead' => ['required', 'boolean'],
            'debounce_minutes' => ['required', 'integer', 'min:'.(int) config('office.debounce_min', 5), 'max:'.(int) config('office.debounce_max', 60)],
            'per_chat_daily' => ['required', 'integer', 'min:1', 'max:'.(int) config('office.per_chat_daily_max', 24)],
            'caps' => ['required', 'array'],
        ];

        foreach (AiUsageChannel::office() as $channel) {
            $max = OfficeSettings::maxCaps($channel);
            $rules["caps.{$channel->value}.requests"] = ['required', 'integer', 'min:0', 'max:'.$max['requests']];
            $rules["caps.{$channel->value}.tokens"] = ['required', 'integer', 'min:0', 'max:'.$max['tokens']];
        }

        return $rules;
    }
}
