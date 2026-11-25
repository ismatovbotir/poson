<?php

namespace App\Actions\Office;

use App\Enums\AiUsageChannel;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
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
 *  - ingest / AI / auto-lead switches; AI on needs the API key;
 *  - debounce 5–60 minutes, per-chat cap 1–24;
 *  - caps per office channel 0 … cap_factor × default;
 *  - the owner allow-list (Telegram user ids): ignored while
 *    OFFICE_OWNER_TELEGRAM_IDS locks it (the rest still saves), and a change needs a recent
 *    password confirmation; audited with old and new ids
 *    (SettingsAudit::recordText; ids are not secret), then every stored
 *    connection's is_allowed is recomputed.
 * Every other changed key is audited without its value.
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
        $owners = OfficeSettings::parseIds($valid['owner_ids'] ?? '');
        // Locked by OFFICE_OWNER_TELEGRAM_IDS: the submitted ids are ignored (never a change, never stored), so saves still work.
        $ownersChanged = ! OfficeSettings::ownerIdsLocked()
            && $owners !== OfficeSettings::parseIds(Setting::query()->where('key', OfficeSettings::OWNERS)->value('value') ?? []);

        if ($ownersChanged && ! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['owner_ids' => __('office.errors.password_required')]);
        }

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

        if (! OfficeSettings::ownerIdsLocked()) {
            $values[OfficeSettings::OWNERS] = $owners;
        }

        $changed = DB::transaction(function () use ($values, $actor, $ip): int {
            $changed = 0;

            foreach ($values as $key => $value) {
                $row = Setting::query()->where('key', $key)->lockForUpdate()->first();

                if ($row !== null && $row->value === $value) {
                    continue;
                }

                $old = $row?->value;

                if ($row === null) {
                    Setting::query()->create(['key' => $key, 'group' => SettingsArea::Office->group(), 'value' => $value]);
                } else {
                    $row->forceFill(['group' => SettingsArea::Office->group(), 'value' => $value])->save();
                }

                if ($key === OfficeSettings::OWNERS) {
                    SettingsAudit::recordText(SettingsArea::Office, $key, AuditAction::Updated, $actor, $ip, implode(', ', OfficeSettings::parseIds($old ?? [])), implode(', ', (array) $value));
                } elseif (is_bool($value)) {
                    SettingsAudit::record(SettingsArea::Office, $key, $value ? AuditAction::Enabled : AuditAction::Disabled, $actor, $ip);
                } else {
                    SettingsAudit::record(SettingsArea::Office, $key, AuditAction::Updated, $actor, $ip);
                }

                $changed++;
            }

            return $changed;
        });

        OfficeSettings::forget();
        // Every successful save: connections stored before a lock or allow-list change get their flag again (VO-1).
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
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'ingest_enabled' => ['required', 'boolean'],
            'ai_enabled' => ['required', 'boolean'],
            'auto_lead' => ['required', 'boolean'],
            'owner_ids' => ['nullable', 'string', 'max:300', 'regex:/^[\d\s,;]*$/'],
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
