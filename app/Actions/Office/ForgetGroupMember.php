<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupThread;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Forget one group member (plan 44 §2, OG-8, office.mark = Admin only, a
 * recent password confirmation): the member's stored messages are deleted,
 * the shared phone is nulled and the message counter reset. Summary, next
 * step, draft and topic (and an analysis's lead hints) are nulled on every analysis and
 * thread of the group that included the member (member_ids), and on every
 * row whose inclusion is unknown (member_ids null). The member row
 * (first_name, username, role and links) stays on purpose: it is the
 * staff's own record of who is in the group and what role they were given,
 * and it is the key the next message of the person upserts into. Audited
 * with counts only (never a name or text).
 *
 * M2: runs under the group's OfficeLock (waits office.lock_wait_seconds for
 * a running analysis, else "analysis running") and the group row lock, so
 * an analysis can never save text from the forgotten messages; the
 * analysis also re-checks its message ids before saving.
 */
class ForgetGroupMember
{
    /**
     * @return array{messages: int, analyses: int, threads: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group, int $memberId, ?string $ip = null): array
    {
        if (! Gate::forUser($actor)->allows('mark', $group)) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['password' => __('office.errors.password_required')]);
        }

        $write = fn (): array => DB::transaction(function () use ($actor, $group, $memberId, $ip): array {
            TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $member = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->whereKey($memberId)->lockForUpdate()->first();

            if (! $member instanceof TelegramGroupMember) {
                throw ValidationException::withMessages(['members' => __('office.groups.errors.members_invalid')]);
            }

            $messages = $member->messages()->delete();

            $member->forceFill(['phone' => null, 'message_count' => 0])->save();

            $blank = ['summary' => null, 'next_step' => null];

            $analysisIds = self::including(TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->get(['id', 'member_ids']), $member->id);
            $analyses = $analysisIds === [] ? 0 : TelegramGroupAnalysis::query()->whereKey($analysisIds)->update($blank + ['draft_reply' => null, 'topic' => null, 'lead_fields' => null]);

            $threadIds = self::including(TelegramGroupThread::query()->where('telegram_group_id', $group->id)->get(['id', 'client_member_id', 'member_ids']), $member->id);
            $threads = $threadIds === [] ? 0 : TelegramGroupThread::query()->whereKey($threadIds)->update($blank + ['draft' => null, 'topic' => null]);

            SettingsAudit::recordText(
                SettingsArea::Office,
                'office.group.'.$group->id.'.member_forgotten',
                AuditAction::Forgotten,
                $actor,
                $ip,
                '',
                "messages={$messages} analyses={$analyses} threads={$threads}",
            );

            return ['messages' => $messages, 'analyses' => $analyses, 'threads' => $threads];
        });

        try {
            return OfficeLock::forGroup($group->id)->block((int) config('office.lock_wait_seconds', 5), $write);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['members' => __('office.errors.analysis_running')]);
        }
    }

    /**
     * The ids of the rows that included the member, or whose inclusion is unknown (member_ids null).
     *
     * @param  Collection<int, TelegramGroupAnalysis>|Collection<int, TelegramGroupThread>  $rows
     * @return list<int>
     */
    private static function including(Collection $rows, int $memberId): array
    {
        return $rows->filter(function (TelegramGroupAnalysis|TelegramGroupThread $row) use ($memberId): bool {
            if ($row instanceof TelegramGroupThread && $row->client_member_id === $memberId) {
                return true;
            }

            return ! is_array($row->member_ids) || in_array($memberId, array_map('intval', $row->member_ids), true);
        })->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }
}
