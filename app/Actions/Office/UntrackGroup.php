<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\GroupStatus;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Untrack a Telegram group (plan 44 §2, OG-12, office.mark = Admin only, a
 * recent password confirmation enforced here): under the group's OfficeLock
 * (waits office.lock_wait_seconds for a running analysis, else "analysis
 * running") the status goes back to untracked FIRST (so ingest and analysis
 * stop at once), the assignee, customer, tracking audit and analysis state
 * are cleared, then the phones are nulled and the messages, threads,
 * analyses and members are deleted in chunks. Leads (and the lead link
 * records on them) are kept. The group row stays as an untracked stub, so it
 * can be tracked again; retention deletes the stub after 30 days of silence.
 * An untracked group that still holds data (a purge that stopped halfway) can be untracked again: it
 * purges and is audited as a retry. Audited with counts only.
 */
class UntrackGroup
{
    public function __construct(protected PurgeGroupData $purge) {}

    /**
     * @return array{messages: int, threads: int, analyses: int, members: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group, ?string $ip = null): array
    {
        if (! Gate::forUser($actor)->allows('mark', $group)) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['group' => __('office.errors.password_required')]);
        }

        $run = function () use ($actor, $group, $ip): array {
            $retry = false;
            $stopped = DB::transaction(function () use ($group, &$retry): bool {
                $locked = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isTracked()) {
                    // A retry after a partial purge: an untracked group that still holds data.
                    $retry = $locked->status === GroupStatus::Untracked && PruneGroupData::holdsData($locked);

                    return $retry;
                }

                $locked->forceFill([
                    'status' => GroupStatus::Untracked,
                    'assignee_id' => null,
                    'customer_id' => null,
                    'tracked_by_id' => null,
                    'tracked_at' => null,
                    'analysis_due_at' => null,
                    'last_analysed_message_id' => null,
                ])->save();

                return true;
            });

            if (! $stopped) {
                throw ValidationException::withMessages(['group' => __('office.groups.errors.not_tracked')]);
            }

            $counts = $this->purge->handle($group);

            SettingsAudit::recordText(
                SettingsArea::Office,
                'office.group.'.$group->id.'.untracked',
                AuditAction::GroupUntracked,
                $actor,
                $ip,
                '',
                ($retry ? 'retry ' : '')."messages={$counts['messages']} members={$counts['members']} analyses={$counts['analyses']} threads={$counts['threads']}",
            );

            return $counts;
        };

        try {
            return OfficeLock::forGroup($group->id)->block((int) config('office.lock_wait_seconds', 5), $run);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['group' => __('office.errors.analysis_running')]);
        }
    }
}
