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
 * minute and PER_USER_PER_HOUR per person (T21-16).
 *
 * Retried: failed, not_configured and limited files, a "stuck" claim
 * (sending for more than TelegramMessage::STUCK_MINUTES, the worker died:
 * only this explicit staff action reclaims it, B4-S11c) and a pending file
 * older than that (its job never ran, e.g. the bot was off; L1).
 *
 * In one transaction, with the message row locked and read again, so a stale
 * call writes no audit row and keeps the cap decision: policy, kind / status,
 * banned, bot off, then the limits. Each refusal is an InboxRefused with its
 * reason, and a refused call never uses up the per-minute token. A queued retry
 * writes a SettingsAudit row holding the conversation id only, no text or file
 * name. The forward job claims the row again; the file's first cap decision is
 * cleared, so a staff retry is counted again under the same caps (TG-26, M1).
 */
class RetryForward
{
    public const PER_MINUTE_KEY = 'telegram-retry-forward:';

    public const PER_USER_KEY = 'telegram-retry-forward-user:';

    public const PER_USER_PER_HOUR = 30;

    /**
     * @return true a forward was queued
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(User $user, TelegramMessage $message, ?string $ip = null): true
    {
        return DB::transaction(function () use ($user, $message, $ip): true {
            $row = TelegramMessage::query()->whereKey($message->getKey())->lockForUpdate()->firstOrFail();
            $conversation = $row->telegramUser;

            if ($conversation === null) {
                throw new InboxRefused('forward_not_retryable');
            }

            Gate::forUser($user)->authorize('view', $conversation);

            if (! $row->isForwardRetryable()) {
                throw new InboxRefused('forward_not_retryable');
            }

            if ($conversation->isBanned()) {
                throw new InboxRefused('banned');
            }

            if (! BotSwitch::isEnabled()) {
                throw new InboxRefused('bot_off');
            }

            $this->hitLimits($user, $row);

            if ($row->forward_status === TelegramForwardStatus::Sending) {
                $reclaimed = TelegramMessage::query()
                    ->whereKey($row->id)
                    ->stuck()
                    ->where('forward_status', TelegramForwardStatus::Sending)
                    ->update(['forward_status' => TelegramForwardStatus::Pending, 'updated_at' => now()]);

                if ($reclaimed === 0) {
                    throw new InboxRefused('busy');
                }
            }

            Cache::forget(ForwardTelegramMedia::capDecisionKey($row->id));
            dispatch(new ForwardTelegramMedia($row->id, $row->telegram_user_id));

            SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$row->telegram_user_id, AuditAction::RetryForward, $user, $ip);

            return true;
        });
    }

    /**
     * The per-user limit is looked at first, so a person over it does not use
     * up the file's minute. The hits themselves decide (no check-then-hit race).
     *
     * @throws InboxRefused
     */
    private function hitLimits(User $user, TelegramMessage $row): void
    {
        $userKey = self::PER_USER_KEY.$user->id;

        if (RateLimiter::tooManyAttempts($userKey, self::PER_USER_PER_HOUR)) {
            throw new InboxRefused('too_many_actions');
        }

        if (RateLimiter::hit(self::PER_MINUTE_KEY.$row->id, 60) > 1) {
            throw new InboxRefused('busy');
        }

        if (RateLimiter::hit($userKey, 3600) > self::PER_USER_PER_HOUR) {
            throw new InboxRefused('too_many_actions');
        }
    }
}
