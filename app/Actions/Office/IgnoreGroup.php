<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\GroupStatus;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ignore an untracked Telegram group (plan 44 §2, OG-12, office.mark =
 * Admin only): the stub keeps its counters only, the title is nulled and is
 * never refreshed again. A tracked group cannot be ignored (untracking it
 * is a separate, password-protected step that deletes its data).
 */
class IgnoreGroup
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group, ?string $ip = null): TelegramGroup
    {
        if (! Gate::forUser($actor)->allows('mark', $group)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $group, $ip): TelegramGroup {
            $locked = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTracked()) {
                throw ValidationException::withMessages(['group' => __('office.groups.errors.tracked_cannot_ignore')]);
            }

            $locked->forceFill(['status' => GroupStatus::Ignored, 'title' => null, 'username' => null, 'analysis_due_at' => null])->save();

            SettingsAudit::record(SettingsArea::Office, 'office.group.'.$locked->id.'.ignore', AuditAction::GroupIgnored, $actor, $ip);

            return $locked;
        });
    }
}
