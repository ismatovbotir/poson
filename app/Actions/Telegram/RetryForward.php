<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Enums\TelegramForwardStatus;
use App\Jobs\ForwardTelegramMedia;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff retry of a customer file that did not reach the inbox chat (plan 14
 * v2 §6 step 7, plan 21 A5). Needs "view" on the conversation
 * (TelegramUserPolicy), so any viewer may retry. At most once per message per
 * minute and PER_USER_PER_HOUR per person (T21-16). Refused while the bot is
 * off or the user is banned, since the job would do nothing.
 *
 * Retried: failed, not_configured and limited files, a "stuck" claim
 * (sending for more than TelegramMessage::STUCK_MINUTES, the worker died:
 * only this explicit staff action reclaims it, B4-S11c) and a pending file
 * older than that (its job never ran, e.g. the bot was off; L1). The check
 * order is: policy, kind / status, bot state, then the limits, so a refused
 * call never uses up the minute. A queued retry writes a SettingsAudit row
 * (RetryForward) holding the conversation id only, no text or file name. The
 * forward job claims the row again; the file's first cap decision is cleared,
 * so a staff retry is counted again under the same caps (TG-26, M1).
 */
class RetryForward
{
    public const PER_MINUTE_KEY = 'telegram-retry-forward:';

    public const PER_USER_KEY = 'telegram-retry-forward-user:';

    public const PER_USER_PER_HOUR = 30;

    /**
     * @return bool whether a forward was queued
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, TelegramMessage $message, ?string $ip = null): bool
    {
        $conversation = $message->telegramUser;

        if ($conversation === null) {
            return false;
        }

        Gate::forUser($user)->authorize('view', $conversation);

        if (! $message->isForwardRetryable() || $conversation->isBanned() || ! BotSwitch::isEnabled()) {
            return false;
        }

        // Atomic: the hit itself decides (no check-then-hit race).
        if (RateLimiter::hit(self::PER_MINUTE_KEY.$message->id, 60) > 1) {
            return false;
        }

        if (RateLimiter::hit(self::PER_USER_KEY.$user->id, 3600) > self::PER_USER_PER_HOUR) {
            return false;
        }

        return DB::transaction(function () use ($user, $message, $ip): bool {
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

            SettingsAudit::record(SettingsArea::TelegramBot, 'telegram.conversation.'.$message->telegram_user_id, AuditAction::RetryForward, $user, $ip);

            return true;
        });
    }
}
