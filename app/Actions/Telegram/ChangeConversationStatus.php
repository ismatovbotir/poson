<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\ConversationStatus;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Close or reopen a bot conversation (plan 21 A3). Policy "close" (view) is
 * checked against the row as it is now; the change is a conditional update, so
 * only a real change writes an Activity row. An inbound message also reopens a
 * closed conversation (StoreInboundMessage).
 */
class ChangeConversationStatus
{
    /**
     * @return bool whether the status changed
     *
     * @throws AuthorizationException
     */
    public function close(TelegramUser $conversation, User $actor): bool
    {
        return $this->change($conversation, $actor, ConversationStatus::Open, ConversationStatus::Closed);
    }

    /**
     * @return bool whether the status changed
     *
     * @throws AuthorizationException
     */
    public function reopen(TelegramUser $conversation, User $actor): bool
    {
        return $this->change($conversation, $actor, ConversationStatus::Closed, ConversationStatus::Open);
    }

    /**
     * @throws AuthorizationException
     */
    private function change(TelegramUser $conversation, User $actor, ConversationStatus $from, ConversationStatus $to): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $from, $to): bool {
            $fresh = TelegramUser::query()->findOrFail($conversation->getKey());

            Gate::forUser($actor)->authorize('close', $fresh);

            $changed = TelegramUser::query()
                ->whereKey($fresh->id)
                ->where('conversation_status', $from)
                ->update(['conversation_status' => $to, 'closed_at' => $to === ConversationStatus::Closed ? now() : null]);

            if ($changed !== 1) {
                return false;
            }

            $fresh->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => ['from' => $from->value, 'to' => $to->value],
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
