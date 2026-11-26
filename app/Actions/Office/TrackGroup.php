<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\GroupStatus;
use App\Enums\SettingsArea;
use App\Jobs\FetchGroupAdministrators;
use App\Models\Customer;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Track a Telegram group (plan 44 §2, OG-4, office.mark = Admin only). It
 * needs an active office assignee, an optional customer and the tick "a
 * privacy note was posted in the group by staff": without the tick nothing
 * changes. The tick is audited (counts-free). Only an untracked or ignored
 * group can be tracked; messages are stored from the next one on. Then the
 * (throttled) administrator fetch is queued.
 */
class TrackGroup
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group, int $assigneeId, ?int $customerId, bool $privacyNotePosted, ?string $ip = null): TelegramGroup
    {
        if (! Gate::forUser($actor)->allows('mark', $group)) {
            throw new AuthorizationException;
        }

        if (! $privacyNotePosted) {
            throw ValidationException::withMessages(['privacyNote' => __('office.groups.errors.privacy_required')]);
        }

        $assignee = OfficeStaff::requireAssignable($assigneeId, 'assignee');

        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages(['customerId' => __('office.groups.errors.customer_invalid')]);
        }

        return DB::transaction(function () use ($actor, $group, $assignee, $customerId, $ip): TelegramGroup {
            $locked = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTracked()) {
                throw ValidationException::withMessages(['group' => __('office.groups.errors.already_tracked')]);
            }

            $locked->forceFill([
                'status' => GroupStatus::Tracked,
                'assignee_id' => $assignee->id,
                'customer_id' => $customerId,
                'tracked_by_id' => $actor->id,
                'tracked_at' => now(),
            ])->save();

            SettingsAudit::recordText(SettingsArea::Office, 'office.group.'.$locked->id.'.track', AuditAction::GroupTracked, $actor, $ip, '', 'privacy_note_posted=1');

            FetchGroupAdministrators::dispatchFor($locked);

            return $locked;
        });
    }
}
