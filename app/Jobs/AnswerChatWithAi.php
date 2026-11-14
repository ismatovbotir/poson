<?php

namespace App\Jobs;

use App\Actions\Chat\AiAnswer;
use App\Actions\Chat\AnswerWithAi;
use App\Actions\Chat\HandleChatInput;
use App\Actions\Chat\RecordAiUsage;
use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
use App\Services\Ai\ChatModel;
use App\Services\Telegram\TelegramClient;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\LeadDialog;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BotText;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answer one stored customer turn with the AI model (plan 30 §2.3, AI-2,
 * AI-15, AI-16). Payload: scalars only (turn id, telegram_users id, channel):
 * no text, no key. ChatModel is injected into handle() (never the
 * constructor). tries 1, timeout 30 (below the queue's retry_after), queued
 * after commit.
 *
 *  1. Lock the telegram_users row (same order as the webhook) and re-check:
 *     - superseded (silent, no call): unregistered, banned, not in bot mode
 *       or waiting for staff, a lead dialog started, a newer turn or another
 *       answer delivered (bot_dialog["ai"]), an answer row already stored;
 *     - disabled (M1): AI switched off, key gone or paused (401/403) while
 *       the bot is on → the rule reply is replayed, no call.
 *  2. Re-run the engine on the redacted turn: when a rule now answers it
 *     directly, that reply is sent with no call (superseded/"direct").
 *  3. Build the prompt, reserve the daily cap atomically (over: "capped",
 *     rule reply). The reservation is remembered in the cache by turn id,
 *     so failed() can settle it if the worker dies later (M2).
 *  4. "typing…" (3 s, errors swallowed), then the call outside any
 *     transaction. ANY Throwable from here on becomes a "failed" answer:
 *     the reservation is settled and the rule reply replayed (M2).
 *  5. Fresh switch memo (L3), lock again, re-check again (moot now:
 *     superseded or disabled as in 1, tokens still counted), insert the
 *     assistant row (answers_turn_id is unique: a second run stops here),
 *     settle, then deliverAi() or replay(). SendBotAnswer goes after commit.
 *  A 401/403 pauses AI (AiSwitch) and sends one management alert a day (M3).
 *
 * Logs: ids, status, reason or error class only, never text (AI-21).
 */
class AnswerChatWithAi implements ShouldQueueAfterCommit
{
    use Queueable;

    private const SUPERSEDED = 'superseded';

    private const DISABLED = 'disabled';

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $turnId,
        public int $telegramUserId,
        public string $channel = 'telegram',
    ) {
        $this->onQueue('default');
    }

    public function handle(HandleChatInput $chat, AnswerWithAi $ai, RecordAiUsage $usage, Engine $engine, TelegramClient $telegram, ChatModel $model): void
    {
        $channel = ChatChannel::tryFrom($this->channel) ?? ChatChannel::Telegram;

        $started = DB::transaction(function () use ($chat, $engine): ?array {
            [$user, $turn] = $this->locked();

            if ($user === null || $turn === null) {
                return null;
            }

            $moot = $this->moot($user, $turn);

            if ($moot === self::SUPERSEDED) {
                $this->store($turn, ChatAiStatus::Superseded);

                return null;
            }

            if ($moot === self::DISABLED) {
                if ($this->store($turn, ChatAiStatus::Disabled)) {
                    $context = $this->context($user, $turn);
                    $chat->replay($user, $context, $engine->respond($context));
                }

                return null;
            }

            return [$user, $turn];
        });

        if ($started === null) {
            return;
        }

        [$user, $turn] = $started;

        $context = $this->context($user, $turn);
        $marker = $engine->respond($context, true);

        // Code review 5: a rule answers it directly now (rules changed meanwhile): no call.
        if (! $marker->isAi()) {
            $this->finish($chat, $ai, $usage, $context, $marker, new AiAnswer(ChatAiStatus::Superseded, '', null, 'direct', ''));

            return;
        }

        $original = $marker->original;
        $request = $ai->prepare($context, $turn->text, $marker->ruleId);
        $reserved = $request->estimatedBilledTokens();
        $day = $usage->reserve($channel, $reserved);

        if ($day === null) {
            $usage->capped($channel);
            $this->finish($chat, $ai, $usage, $context, $original, new AiAnswer(ChatAiStatus::Capped, '', null, 'daily_cap', $ai->promptHash($request)));

            return;
        }

        Cache::put($this->reservationKey(), ['day' => $day, 'tokens' => $reserved], 3600);

        try {
            $this->typing($telegram, $user);
            $answer = $ai->answer($model, $request, $channel);
        } catch (Throwable $error) {
            $answer = new AiAnswer(ChatAiStatus::Failed, '', null, mb_substr(class_basename($error), 0, 40), $ai->promptHash($request));
        }

        if ($answer->status === ChatAiStatus::Failed) {
            Log::warning('Chat AI answer failed', ['turn_id' => $this->turnId, 'reason' => $answer->reason]);
        }

        if ($answer->reason === AnswerWithAi::AUTH_FAILED) {
            $this->alertAuthFailed();
        }

        $this->finish($chat, $ai, $usage, $context, $original, $answer);
    }

    /**
     * The worker died or the final transaction threw (M2): settle the
     * reservation as failed and replay the rule reply under the lock, once.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Chat AI job failed', ['turn_id' => $this->turnId, 'error' => $exception !== null ? class_basename($exception) : null]);

        try {
            $channel = ChatChannel::tryFrom($this->channel) ?? ChatChannel::Telegram;
            $reservation = Cache::pull($this->reservationKey());

            if (is_array($reservation)) {
                app(RecordAiUsage::class)->settle($channel, (string) $reservation['day'], (int) $reservation['tokens'], ChatAiStatus::Failed, null);
            }

            DB::transaction(function (): void {
                [$user, $turn] = $this->locked();

                if ($user === null || $turn === null || $this->moot($user, $turn) === self::SUPERSEDED || ! $this->store($turn, ChatAiStatus::Failed)) {
                    return;
                }

                $context = $this->context($user, $turn);
                app(HandleChatInput::class)->replay($user, $context, app(Engine::class)->respond($context));
            });
        } catch (Throwable $error) {
            Log::warning('Chat AI fallback failed', ['turn_id' => $this->turnId, 'error' => class_basename($error)]);
        }
    }

    /**
     * @param  ChatReply  $original  The engine's reply without AI (P5)
     */
    private function finish(HandleChatInput $chat, AnswerWithAi $ai, RecordAiUsage $usage, ChatContext $context, ChatReply $original, AiAnswer $answer): void
    {
        // L3: see a switch-off that happened during the call (memo only, the cache stays).
        ChatbotSwitch::forgetMemo();

        DB::transaction(function () use ($chat, $ai, $usage, $context, $original, $answer): void {
            [$user, $turn] = $this->locked();
            $moot = $user === null || $turn === null ? self::SUPERSEDED : $this->moot($user, $turn);
            $status = match ($moot) {
                self::SUPERSEDED => ChatAiStatus::Superseded,
                self::DISABLED => ChatAiStatus::Disabled,
                default => $answer->status,
            };

            $reservation = Cache::get($this->reservationKey());

            if (is_array($reservation)) {
                $usage->settle($context->channel, (string) $reservation['day'], (int) $reservation['tokens'], $status, $answer->result);
                DB::afterCommit(fn () => Cache::forget($this->reservationKey()));
            }

            if ($turn === null || ! $this->store($turn, $status, $answer) || $moot === self::SUPERSEDED) {
                return;
            }

            if ($status->isDelivered()) {
                $chat->deliverAi($user, $ai->reply($answer, $context->locale));

                return;
            }

            // Failed, refused, blocked, capped, disabled, or a rule that now answers directly: the rule reply (P5).
            $chat->replay($user, $context, $original);
        });
    }

    /**
     * The locked conversation and its user turn (null when gone).
     *
     * @return array{0: ?TelegramUser, 1: ?ChatAiTurn}
     */
    private function locked(): array
    {
        $user = TelegramUser::query()->whereKey($this->telegramUserId)->lockForUpdate()->first();

        if (! $user instanceof TelegramUser) {
            return [null, null];
        }

        $turn = ChatAiTurn::query()
            ->whereKey($this->turnId)
            ->where('telegram_user_id', $user->id)
            ->where('role', ChatAiRole::User->value)
            ->first();

        return [$user, $turn];
    }

    /**
     * @return self::SUPERSEDED|self::DISABLED|null
     */
    private function moot(TelegramUser $user, ChatAiTurn $turn): ?string
    {
        $dialog = is_array($user->bot_dialog) ? $user->bot_dialog : [];

        $superseded = ! BotSwitch::isEnabled()
            || ! ChatbotSwitch::isEnabled($turn->channel)
            || ! $user->isRegistered()
            || $user->isBanned()
            || $user->chat_mode !== ChatMode::Bot
            || $user->isWaitingForStaff()
            || array_key_exists(LeadDialog::KEY, $dialog)
            || ($dialog[HandleChatInput::AI_KEY] ?? null) !== $turn->id
            || ChatAiTurn::query()->where('answers_turn_id', $turn->id)->exists()
            || (int) ChatAiTurn::query()->where('telegram_user_id', $user->id)->where('role', ChatAiRole::User->value)->max('id') !== $turn->id;

        if ($superseded) {
            return self::SUPERSEDED;
        }

        return AiSwitch::isEnabled($turn->channel) ? null : self::DISABLED;
    }

    private function context(TelegramUser $user, ChatAiTurn $turn): ChatContext
    {
        return new ChatContext($turn->channel, BotText::locale($user), $turn->text);
    }

    /**
     * The assistant row for the turn; false when another run already stored one (P4).
     */
    private function store(ChatAiTurn $turn, ChatAiStatus $status, ?AiAnswer $answer = null): bool
    {
        $result = $answer?->result;

        try {
            ChatAiTurn::query()->create([
                'channel' => $turn->channel,
                'telegram_user_id' => $turn->telegram_user_id,
                'web_chat_id' => $turn->web_chat_id,
                'answers_turn_id' => $turn->id,
                'role' => ChatAiRole::Assistant,
                'text' => $status->isDelivered() ? (string) $answer?->text : '',
                'status' => $status,
                'model' => $result !== null ? mb_substr($result->model, 0, 40) : null,
                'input_tokens' => $result->inputTokens ?? 0,
                'output_tokens' => $result->outputTokens ?? 0,
                'cache_read_tokens' => $result->cacheReadTokens ?? 0,
                'cache_write_tokens' => $result->cacheWriteTokens ?? 0,
                'stop_reason' => $result?->stopReason !== null ? mb_substr($result->stopReason, 0, 30) : ($answer?->reason !== null ? mb_substr($answer->reason, 0, 30) : null),
                'prompt_hash' => $answer?->promptHash ?: null,
                'product_ids' => null,
                'latency_ms' => $result?->latencyMs,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Per queued run (its job uuid), so two concurrent runs of one turn never share a reservation;
     * the turn id when the job runs without a queue job (direct calls).
     */
    private function reservationKey(): string
    {
        return 'chatbot:ai:reservation:'.($this->job?->uuid() ?? 'turn-'.$this->turnId);
    }

    /**
     * M3: one management alert per day while the key is rejected (counts only, never the key).
     */
    private function alertAuthFailed(): void
    {
        if (Cache::add('chatbot:ai:auth_alert:'.RecordAiUsage::day(), true, 86400)) {
            MonitoringAlerts::problem('ai_auth_failed', ['reason' => 'HTTP 401/403']);
        }
    }

    /**
     * "typing…" while the model works: best effort, 3 s, never thrown (AI-16).
     */
    private function typing(TelegramClient $telegram, TelegramUser $user): void
    {
        try {
            if ($telegram->isConfigured()) {
                $telegram->withTimeout(3, 3)->sendChatAction((string) $user->telegram_id);
            }
        } catch (Throwable) {
            // Only a hint for the customer.
        }
    }
}
