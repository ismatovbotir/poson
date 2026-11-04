<?php

namespace App\Actions\Telegram;

use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Jobs\ForwardTelegramMedia;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff retry of a customer file that did not reach the inbox chat (plan 14
 * v2 §6 step 7; the inbox button comes in B5). Needs "view" on the
 * conversation (TelegramUserPolicy), at most once per message per minute.
 *
 * Retried: failed, not_configured and limited files, a "stuck" claim
 * (sending for more than TelegramMessage::STUCK_MINUTES, the worker died:
 * only this explicit staff action reclaims it, B4-S11c) and a pending file
 * older than that (its job never ran, e.g. the bot was off; L1). The check
 * order is: policy, kind / status, then the per-minute limit, so a refused
 * call never uses up the minute. The forward job claims the row again; the
 * file's first cap decision is cleared, so a staff retry is counted again
 * under the same caps (TG-26, M1).
 */
class RetryForward
{
    public const PER_MINUTE_KEY = 'telegram-retry-forward:';

    /**
     * @return bool whether a forward was queued
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, TelegramMessage $message): bool
    {
        $conversation = $message->telegramUser;

        if ($conversation === null) {
            return false;
        }

        Gate::forUser($user)->authorize('view', $conversation);

        if ($message->direction !== TelegramDirection::In || ! $message->kind->isForwardable() || ! $this->retryable($message)) {
            return false;
        }

        // Atomic: the hit itself decides (no check-then-hit race).
        if (RateLimiter::hit(self::PER_MINUTE_KEY.$message->id, 60) > 1) {
            return false;
        }

        return DB::transaction(function () use ($message): bool {
            if ($message->forward_status === TelegramForwardStatus::Sending) {
                $reclaimed = TelegramMessage::query()
                    ->whereKey($message->id)
                    ->stuck()
                    ->where('forward_status', TelegramForwardStatus::Sending)
                    ->update(['forward_status' => TelegramForwardStatus::Pending, 'updated_at' => now()]);

                if ($reclaimed === 0) {
                    return false;
                }
            }

            Cache::forget(ForwardTelegramMedia::capDecisionKey($message->id));
            dispatch(new ForwardTelegramMedia($message->id, $message->telegram_user_id));

            return true;
        });
    }

    private function retryable(TelegramMessage $message): bool
    {
        return in_array($message->forward_status, [TelegramForwardStatus::Failed, TelegramForwardStatus::NotConfigured, TelegramForwardStatus::Limited], true)
            || (in_array($message->forward_status, [TelegramForwardStatus::Sending, TelegramForwardStatus::Pending], true)
                && $message->updated_at?->lt(now()->subMinutes(TelegramMessage::STUCK_MINUTES)) === true);
    }
}
