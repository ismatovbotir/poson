<?php

namespace App\Actions\Telegram;

use App\Enums\TelegramMessageStatus;
use App\Jobs\DeliverBotMessage;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff retry of an undelivered reply (plan 21 A2, T21-13): a failed row, a
 * pending one whose job never sent it (the bot was off), or a stuck "sending"
 * one (the worker died; the customer may already have it, so the screen warns,
 * which is UX only). Author or Admin only (policy "resend").
 *
 * Ownerless rows (the auto-ack) are not retryable, for an Admin either. The
 * row moves back to pending by one conditional update and the job is
 * dispatched only when that update changed exactly one row, so concurrent
 * calls send once.
 */
class ResendTelegramMessage
{
    public function __construct(private readonly SendTelegramReply $replies) {}

    /**
     * @return bool whether a delivery was queued
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramMessage $message, User $actor): bool
    {
        return DB::transaction(function () use ($message, $actor): bool {
            $conversation = TelegramUser::query()->whereKey($message->telegram_user_id)->lockForUpdate()->firstOrFail();
            $row = TelegramMessage::query()->whereKey($message->getKey())->where('telegram_user_id', $conversation->id)->firstOrFail();

            Gate::forUser($actor)->authorize('resend', [$conversation, $row]);

            if (! $row->isResendable()) {
                throw new InboxRefused('not_retryable');
            }

            $refusal = $this->replies->refusal($conversation);

            if ($refusal !== null) {
                throw new InboxRefused($refusal);
            }

            $this->replies->hitLimit($actor);

            $changed = TelegramMessage::query()
                ->whereKey($row->id)
                ->resendable()
                ->update(['status' => TelegramMessageStatus::Pending, 'error_code' => null, 'updated_at' => now()]);

            if ($changed !== 1) {
                return false;
            }

            dispatch(new DeliverBotMessage($row->id));

            return true;
        });
    }
}
