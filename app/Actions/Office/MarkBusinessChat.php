<?php

namespace App\Actions\Office;

use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\BusinessChatKind;
use App\Enums\SettingsArea;
use App\Enums\TelegramParty;
use App\Models\BusinessChat;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Mark a Telegram Business chat (plan 41 §4, office.mark = Admin only):
 *
 *  - client: marked_at / by, unread reset, optional assignee (active, office.chats);
 *    messages are stored from the next one on (nothing before the mark);
 *  - employee: optional staff account; nothing is ever stored;
 *  - ignored: never surfaced again (the prune nulls the name after 14 days).
 *
 * A client chat marked as something else needs a recent password
 * confirmation (VO-12) and takes the chat's analysis lock first: its
 * messages and analyses are deleted (the lead stays) and a running
 * analysis can no longer save. The matching bot user (telegram_users by
 * telegram id) gets the party (and staff account). Each mark: an activity
 * on the chat and a counts-only audit row (never a name).
 */
class MarkBusinessChat
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessChat $chat, BusinessChatKind $kind, ?int $assigneeId = null, ?int $staffUserId = null, ?string $ip = null): BusinessChat
    {
        if (! Gate::forUser($actor)->allows('mark', $chat) || $kind === BusinessChatKind::Unmarked) {
            throw new AuthorizationException;
        }

        $assignee = $kind === BusinessChatKind::Client && $assigneeId !== null ? OfficeStaff::requireAssignable($assigneeId) : null;
        $staff = $kind === BusinessChatKind::Employee && $staffUserId !== null ? OfficeStaff::requireActive($staffUserId) : null;
        // The path (password + analysis lock) is decided from the CURRENT kind, never the caller's model;
        // the write re-checks it under the row lock (a concurrent mark cannot skip the password or the lock).
        $current = BusinessChat::query()->whereKey($chat->id)->first(['id', 'kind'])?->kind;
        $unmarksClient = $current === BusinessChatKind::Client && $kind !== BusinessChatKind::Client;

        if ($unmarksClient && ! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['password' => __('office.errors.password_required')]);
        }

        $write = fn (): BusinessChat => DB::transaction(function () use ($actor, $chat, $kind, $assignee, $staff, $ip, $unmarksClient): BusinessChat {
            $locked = BusinessChat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $from = $locked->kind;

            if ($from === BusinessChatKind::Client && $kind !== BusinessChatKind::Client && ! $unmarksClient) {
                throw ValidationException::withMessages(['kind' => __('office.errors.chat_changed')]);
            }

            if ($from === BusinessChatKind::Client && $kind !== BusinessChatKind::Client) {
                ForgetDraftFeedback::forChats($locked->id);
                $locked->analyses()->delete();
                $locked->messages()->delete();
                $locked->analysis_due_at = null;
                $locked->last_analysed_message_id = null;
            }

            $locked->kind = $kind;
            $locked->marked_at = now();
            $locked->marked_by_id = $actor->id;
            $locked->unread_count = 0;

            if ($kind === BusinessChatKind::Client) {
                $locked->assignee_id = $assignee?->id ?? ($from === BusinessChatKind::Client ? $locked->assignee_id : null);
                $locked->staff_user_id = null;
            } else {
                $locked->assignee_id = null;
                $locked->staff_user_id = $kind === BusinessChatKind::Employee ? $staff?->id : null;
            }

            $locked->telegram_user_id = $this->linkBotUser($locked, $kind);
            $locked->save();

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::OfficeMark,
                'meta' => array_filter(['from' => $from->value, 'to' => $kind->value, 'assignee_id' => $locked->assignee_id], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            SettingsAudit::record(SettingsArea::Office, 'office.chat.'.$locked->id.'.kind', AuditAction::Marked, $actor, $ip);

            return $locked;
        });

        if (! $unmarksClient) {
            return $write();
        }

        try {
            return OfficeLock::forChat($chat->id)->block((int) config('office.lock_wait_seconds', 5), $write);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['kind' => __('office.errors.analysis_running')]);
        }
    }

    /**
     * The bot user with the same Telegram id: client / employee set the party (an employee keeps an
     * existing staff account when none is given); ignored clears the party and the staff account (L2).
     */
    protected function linkBotUser(BusinessChat $chat, BusinessChatKind $kind): ?int
    {
        $botUser = TelegramUser::query()->where('telegram_id', $chat->telegram_chat_id)->first();

        if (! $botUser instanceof TelegramUser) {
            return null;
        }

        $botUser->forceFill(match ($kind) {
            BusinessChatKind::Client => ['party' => TelegramParty::Client, 'staff_user_id' => null],
            BusinessChatKind::Employee => ['party' => TelegramParty::Employee, 'staff_user_id' => $chat->staff_user_id ?? $botUser->staff_user_id],
            default => ['party' => null, 'staff_user_id' => null],
        })->save();

        return $botUser->id;
    }
}
