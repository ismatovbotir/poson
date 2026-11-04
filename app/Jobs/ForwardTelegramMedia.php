<?php

namespace App\Jobs;

use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\ForwardAlerts;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\InboxMessage;
use App\Support\Telegram\TelegramLocale;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Copy one customer file from the bot chat to the inbox chat (plan 14 v2 §6,
 * TG-21…26, B4-S1…S9). Payload: the telegram_messages and telegram_users ids.
 *
 * Runs under a per-conversation lock (WithoutOverlapping "tg-fwd:{id}",
 * B4-S3), so the header decision, header send and header save never race
 * (albums arrive as several messages at once). Files are copied in the
 * order the queue runs them: normally the order they arrived, but a file
 * released after a 429 or a lock wait is copied after the later ones.
 *
 *  1. Missing row, not an inbound forwardable kind, banned user or kill
 *     switch off → nothing happens (status stays as it is).
 *  2. Claim (TG-25): forward_status pending | failed | not_configured |
 *     limited → sending, a conditional update; forwarded / sending are skipped.
 *  3. Target (B4-S1/S2): InboxChat::id() (inbox, else sales; ^-[1-9]\d{0,19}$) and the
 *     cached getChat check (private group). No token / no or refused target
 *     → not_configured (refused: one management alert an hour).
 *  4. Caps (TG-26, atomic RateLimiter hits): > CONVERSATION_PER_HOUR from one
 *     conversation or > DAILY_TOTAL a day → limited, one management alert a day.
 *     Counted once per message (M1): the first decision is kept in a
 *     Cache::add marker, so 429 / 5xx retries reuse it; RetryForward clears it.
 *  5. Header (TG-24, B4-S7): a new one unless the last one went to the same
 *     chat less than HEADER_MINUTES ago; saved (id, time, chat) before the copy,
 *     so a retry never repeats it. No buttons until the B5 admin route exists.
 *  6. copyMessage (TG-21, B4-S4; the chat is saved with the copy id in forwarded_chat_id, T21-9): reply to the header, empty reply_markup, an
 *     explicit plain caption ('' when empty) for captioned kinds, none for
 *     sticker / location / video_note.
 *
 * Failures (B4-S8): 429 → back to pending, released for retry_after (the
 * release counts as an attempt, but only retryUntil and $maxExceptions end
 * the job, so a 429 never uses up a real retry); every other 4xx → failed + code, a 400 / 403
 * drops the cached chat check, a 403, any 400 on the header or the third copy 400 in an hour alerts
 * the management chat as lost_target on a 403 or 400 of the header only (deduped); a copy
 * 400 / 403 re-verifies the chat at once and alerts refused_target when it fails (T21-29);
 * 5xx / timeout → back to pending and thrown (backoff 30/120/600/1800),
 * failed after $maxExceptions. Any other error after the claim (database,
 * bug) also puts the row back to pending and is rethrown, so nothing stays
 * "sending". Logs (B4-S9): ids, kind, status and code only.
 */
class ForwardTelegramMedia implements ShouldQueueAfterCommit
{
    use Queueable;

    public const RETRY_HOURS = 6;

    public const HEADER_MINUTES = 10;

    public const CONVERSATION_PER_HOUR = 30;

    public const DAILY_TOTAL = 300;

    public const DAILY_KEY = 'telegram-forward:day';

    public const MONITORING_EVENT = ForwardAlerts::MONITORING_EVENT;

    /** Where a Telegram call failed (L4): the chat check, the header or the copy. */
    private const PHASE_CHECK = 'check';

    private const PHASE_HEADER = 'header';

    private const PHASE_COPY = 'copy';

    /**
     * 429 releases and lock waits must not use up the retries: the job may be
     * retried for RETRY_HOURS, while real failures are capped here.
     */
    public int $maxExceptions = 5;

    /**
     * Seconds a run may take (getChat + header + copy, 10 s each at most);
     * a run killed by the timeout is failed, not retried (L2), so a copy
     * that may have reached Telegram is never sent twice automatically.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /**
     * How long the first cap decision of a message is kept (longer than the
     * retry window), so retries never count the same file again (M1).
     */
    public const CAP_DECISION_HOURS = self::RETRY_HOURS + 1;

    public function __construct(public int $telegramMessageId, public int $telegramUserId)
    {
        $this->onQueue('default');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(self::lockKey($this->telegramUserId)))->releaseAfter(5)->expireAfter(180)];
    }

    public static function lockKey(int $telegramUserId): string
    {
        return 'tg-fwd:'.$telegramUserId;
    }

    public static function capDecisionKey(int $telegramMessageId): string
    {
        return 'telegram-forward:counted:'.$telegramMessageId;
    }

    public static function conversationKey(int $telegramUserId): string
    {
        return 'telegram-forward:conversation:'.$telegramUserId;
    }

    public function handle(TelegramClient $client): void
    {
        $message = TelegramMessage::query()->with('telegramUser')->find($this->telegramMessageId);
        $user = $message?->telegramUser;

        if (! $message instanceof TelegramMessage
            || ! $user instanceof TelegramUser
            || $user->id !== $this->telegramUserId
            || $message->direction !== TelegramDirection::In
            || ! $message->kind->isForwardable()
            || $message->telegram_message_id === null
            || $user->isBanned()
            || ! BotSwitch::isEnabled()) {
            return;
        }

        if (! $this->claim($message)) {
            return;
        }

        try {
            $this->forward($client, $message, $user);
        } catch (TelegramException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Not a Telegram answer (database, bug): never leave the claim behind.
            if ($message->fresh()?->forward_status === TelegramForwardStatus::Sending) {
                $this->mark($message, TelegramForwardStatus::Pending, $message->forward_error_code ?? 0);
            }

            throw $exception;
        }
    }

    /**
     * Steps 3–6 for a claimed row.
     *
     * @throws TelegramException on transient Telegram failures (retried)
     */
    private function forward(TelegramClient $client, TelegramMessage $message, TelegramUser $user): void
    {
        $chatId = InboxChat::id();

        if (! $client->isConfigured() || InboxChat::key() === null) {
            $this->mark($message, TelegramForwardStatus::NotConfigured);

            return;
        }

        if ($chatId === null) {
            // Configured but not a numeric group id (e.g. @username): refused.
            $this->mark($message, TelegramForwardStatus::NotConfigured);
            ForwardAlerts::once('refused_target', 3600);

            return;
        }

        try {
            if (! InboxChat::verify($client, $chatId)) {
                $this->mark($message, TelegramForwardStatus::NotConfigured);
                ForwardAlerts::once('refused_target', 3600);

                return;
            }

            $limit = $this->overLimit($message, $user);

            if ($limit !== null) {
                $this->mark($message, TelegramForwardStatus::Limited);
                ForwardAlerts::once($limit, 86400);

                return;
            }
        } catch (TelegramException $exception) {
            $this->failure($client, $message, $chatId, $exception, self::PHASE_CHECK);

            return;
        }

        try {
            $headerId = $this->header($client, $user, $chatId);
        } catch (TelegramException $exception) {
            $this->failure($client, $message, $chatId, $exception, self::PHASE_HEADER);

            return;
        }

        try {
            $copiedId = $client->copyMessage($chatId, (string) $user->telegram_id, (int) $message->telegram_message_id, $headerId, $this->caption($message));
        } catch (TelegramException $exception) {
            $this->failure($client, $message, $chatId, $exception, self::PHASE_COPY);

            return;
        }

        $message->forceFill([
            'forward_status' => TelegramForwardStatus::Forwarded,
            'forwarded_message_id' => $copiedId > 0 ? $copiedId : null,
            'forwarded_chat_id' => $copiedId > 0 ? $this->storableChatId($chatId) : null,
            'forward_error_code' => null,
        ])->save();
    }

    /**
     * Retries used up (5xx / timeouts, or still rate-limited after
     * RETRY_HOURS): the file is failed, keeping the last HTTP code.
     */
    public function failed(?Throwable $exception): void
    {
        $message = TelegramMessage::query()->find($this->telegramMessageId);

        if ($message instanceof TelegramMessage && in_array($message->forward_status, [TelegramForwardStatus::Pending, TelegramForwardStatus::Sending], true)) {
            $code = $exception instanceof TelegramException && $exception->status > 0 ? $exception->status : $message->forward_error_code;
            $this->mark($message, TelegramForwardStatus::Failed, $code ?? 0);
        }
    }

    /**
     * Only one run can move a claimable status to sending (TG-25).
     */
    private function claim(TelegramMessage $message): bool
    {
        $claimed = TelegramMessage::query()
            ->whereKey($message->id)
            ->whereIn('forward_status', TelegramForwardStatus::claimable())
            ->update(['forward_status' => TelegramForwardStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $message->forceFill(['forward_status' => TelegramForwardStatus::Sending])->syncOriginalAttribute('forward_status');

        return true;
    }

    /**
     * The cap reached ("conversation_limit" / "daily_limit") or null. Counted
     * on the first decision only; retries of the same message reuse it (M1).
     */
    private function overLimit(TelegramMessage $message, TelegramUser $user): ?string
    {
        $key = self::capDecisionKey($message->id);
        $decided = Cache::get($key);

        if (is_string($decided)) {
            return $decided === 'ok' ? null : $decided;
        }

        $decision = match (true) {
            RateLimiter::hit(self::conversationKey($user->id), 3600) > self::CONVERSATION_PER_HOUR => 'conversation_limit',
            RateLimiter::hit(self::DAILY_KEY, 86400) > self::DAILY_TOTAL => 'daily_limit',
            default => 'ok',
        };

        // A concurrent first decision (impossible under the lock, but cheap to respect) wins.
        if (! Cache::add($key, $decision, self::CAP_DECISION_HOURS * 3600)) {
            $decision = (string) Cache::get($key, $decision);
        }

        return $decision === 'ok' ? null : $decision;
    }

    /**
     * The header message id to reply to: the current one when it went to this
     * chat less than HEADER_MINUTES ago, otherwise a new one, saved at once.
     *
     * @throws TelegramException
     */
    private function header(TelegramClient $client, TelegramUser $user, string $chatId): ?int
    {
        $current = $user->forward_header_message_id !== null
            && $user->forward_header_at?->gt(now()->subMinutes(self::HEADER_MINUTES))
            && (string) $user->forward_header_chat_id === $chatId;

        if ($current) {
            return $user->forward_header_message_id;
        }

        $header = InboxMessage::header($user, TelegramLocale::current());
        $sent = $client->sendMessage($chatId, $header->html, $header->buttons);
        $headerId = is_int($sent['message_id'] ?? null) ? $sent['message_id'] : null;

        $user->forceFill([
            'forward_header_message_id' => $headerId,
            'forward_header_at' => now(),
            'forward_header_chat_id' => $this->storableChatId($chatId),
        ])->save();

        return $headerId;
    }

    /**
     * T21-9: the chat id for the signed bigint column, or null when the
     * pattern's 20 digits would overflow int64 (a wrong id is worse than none).
     */
    private function storableChatId(string $chatId): ?int
    {
        return (string) (int) $chatId === $chatId ? (int) $chatId : null;
    }

    /**
     * Captioned kinds always get an explicit plain caption ('' when empty),
     * so Telegram never copies the original caption or its entities.
     */
    private function caption(TelegramMessage $message): ?string
    {
        return $message->kind->hasCaption() ? BotText::caption($message->text) : null;
    }

    private function failure(TelegramClient $client, TelegramMessage $message, string $chatId, TelegramException $exception, string $phase): void
    {
        $permanent = $exception->status >= 400 && $exception->status < 500 && ! $exception->isRateLimited();
        $status = $permanent ? TelegramForwardStatus::Failed : TelegramForwardStatus::Pending;

        Log::warning('Telegram media forward failed', [
            'telegram_message_id' => $message->id,
            'telegram_user_id' => $message->telegram_user_id,
            'kind' => $message->kind->value,
            'status' => $status->value,
            'code' => $exception->code(),
        ]);

        // Back to pending (with the last code) so the retry can claim it again.
        $this->mark($message, $status, $exception->status);

        if ($exception->isRateLimited()) {
            $this->release($exception->retryAfter ?? $this->backoff()[0]);

            return;
        }

        if (! $permanent) {
            throw $exception;
        }

        if (in_array($exception->status, [400, 403], true)) {
            InboxChat::invalidate($chatId);
        }

        if ($phase === self::PHASE_HEADER && in_array($exception->status, [400, 403], true)) {
            ForwardAlerts::once('lost_target', 3600);

            return;
        }

        if ($phase === self::PHASE_COPY && in_array($exception->status, [400, 403], true)) {
            $this->reverifyTarget($client, $chatId);
        }
    }

    /**
     * T21-29: a copy 400 / 403 can come from the customer's side (deleted
     * source message), so it never alerts by itself. The chat is checked
     * again at once (the cached verdict was dropped): refused → refused_target,
     * passed or transient → no alert.
     */
    private function reverifyTarget(TelegramClient $client, string $chatId): void
    {
        try {
            if (! InboxChat::verify($client, $chatId)) {
                ForwardAlerts::once('refused_target', 3600);
            }
        } catch (TelegramException) {
            // Unknown verdict (transient, token): no alert.
        }
    }

    private function mark(TelegramMessage $message, TelegramForwardStatus $status, int $code = 0): void
    {
        $message->forceFill(['forward_status' => $status, 'forward_error_code' => $code > 0 ? $code : null])->save();
    }
}
