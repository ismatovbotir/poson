<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Add or remove one Virtual office owner (plan 44 §1, OG-3, VO-1): the
 * Telegram user ids whose Business connections are processed. Used by the
 * owner picker and the Allow / Remove buttons of the Connection card.
 *
 * Every check is here, never only in the UI:
 *  - Admin only (office.settings and the Admin role);
 *  - refused while OFFICE_OWNER_TELEGRAM_IDS locks the list in .env;
 *  - a recent password confirmation;
 *  - one positive integer id (a tampered value is refused); at most 10 owners.
 *
 * The delta is merged with the stored list inside a locked transaction, so
 * two Admins never overwrite each other's change. Adding a present id or
 * removing an absent one is a no-op. A change is audited with the old and
 * new ids (SettingsAudit::recordText; ids are not secret), then every
 * stored connection's is_allowed is recomputed. Messages dropped before an
 * owner was allowed stay lost.
 */
class SetOfficeOwners
{
    public const MAX_IDS = 10;

    public const ADD = 'add';

    public const REMOVE = 'remove';

    /**
     * @param  self::ADD|self::REMOVE  $operation
     * @param  mixed  $id  One Telegram user id (Livewire input, validated here)
     * @return bool The list changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $operation, mixed $id, ?string $ip = null): bool
    {
        if (! $actor->hasRole(Role::Admin) || ! $actor->can('office.settings')) {
            throw new AuthorizationException;
        }

        if (OfficeSettings::ownerIdsLocked()) {
            throw ValidationException::withMessages(['owner_ids' => __('office.errors.owners_locked')]);
        }

        if (! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['owner_ids' => __('office.errors.password_required')]);
        }

        $validator = Validator::make(['operation' => $operation, 'id' => $id], [
            'operation' => ['required', 'in:'.self::ADD.','.self::REMOVE],
            // integer alone would accept true; the regex also refuses arrays, floats, signs and 17+ digits.
            'id' => ['required', 'integer', 'regex:/^[1-9]\d{0,15}$/D'],
        ], [], ['id' => __('office.settings.owner_id')]);

        if ($validator->fails()) {
            // One message under the picker (the field of the screen).
            throw ValidationException::withMessages(['owner_ids' => $validator->errors()->first()]);
        }

        $telegramId = (int) $validator->validated()['id'];

        $changed = DB::transaction(function () use ($operation, $telegramId, $actor, $ip): bool {
            // settings.key is unique: the first-ever write can never make a second row.
            $created = Setting::query()->firstOrCreate(['key' => OfficeSettings::OWNERS], ['group' => SettingsArea::Office->group(), 'value' => []]);
            $row = Setting::query()->whereKey($created->getKey())->lockForUpdate()->firstOrFail();
            $old = OfficeSettings::parseIds($row->value ?? []);

            $new = $operation === self::ADD
                ? array_values(array_unique([...$old, $telegramId]))
                : array_values(array_filter($old, fn (int $owner): bool => $owner !== $telegramId));

            if ($new === $old) {
                return false;
            }

            if (count($new) > self::MAX_IDS) {
                throw ValidationException::withMessages(['owner_ids' => __('office.settings.owners_max', ['max' => self::MAX_IDS])]);
            }

            $row->forceFill(['group' => SettingsArea::Office->group(), 'value' => $new])->save();

            SettingsAudit::recordText(SettingsArea::Office, OfficeSettings::OWNERS, AuditAction::Updated, $actor, $ip, implode(', ', $old), implode(', ', $new));

            return true;
        });

        OfficeSettings::forget();
        UpsertBusinessConnection::recomputeAllowed();

        return $changed;
    }
}
