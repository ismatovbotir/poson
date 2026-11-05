<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Jobs\DeleteForwardedCopies;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Admin delete of a conversation (plan 14 v2 §9, plan 21 A7, T21-18). Policy
 * "delete" is checked against the row as it is now, under its lock; the screen
 * asks for a recent password first. In one transaction: the audit row (key
 * telegram.conversation.{id}, no name) and the delete, whose model hook removes
 * the activities and whose foreign key removes the messages.
 *
 * Deleting a banned thread loses the ban. An Admin may delete a thread linked to
 * a lead (provisional owner decision): the lead and its text remain, because a
 * message only points at the lead.
 *
 * The stored staff-chat copies under 47 h are captured before the rows go, and
 * their best-effort removal is queued after the commit (TG-27 amended, B4). The
 * chat is deleted by then, so a failed push is reported, not raised.
 */
class DeleteConversation
{
    /**
     * @throws AuthorizationException
     */
    public function handle(TelegramUser $conversation, User $actor, ?string $ip): void
    {
        $copies = DB::transaction(function () use ($conversation, $actor, $ip): array {
            $locked = TelegramUser::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('delete', $locked);

            SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$locked->id, AuditAction::Deleted, $actor, $ip);

            $copies = DeleteForwardedCopies::pairsFor($locked);

            $locked->delete();

            return $copies;
        });

        if ($copies === []) {
            return;
        }

        try {
            dispatch(new DeleteForwardedCopies($copies));
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
