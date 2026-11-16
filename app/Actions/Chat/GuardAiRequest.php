<?php

namespace App\Actions\Chat;

use App\Enums\AiUsageChannel;
use App\Enums\ChatAiRole;
use App\Enums\ChatChannel;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
use App\Support\Chat\AiSwitch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * May this message go to the AI model? (plan 30 §8, P3, P6, AI-17, AI-19).
 * Runs in the webhook transaction BEFORE the user turn is stored or the job
 * queued; Telegram's BotLimits and bans have already run. Every limit is a
 * RateLimiter::hit (the hit itself decides, no check-then-hit race), per
 * customer first so one chatty customer cannot use up the global limit:
 *
 *  0. the user must pass TelegramUser::mayUseAi() (checked first, burns no quota);
 *  1. the AI switch, the key and the chat bot switch (AiSwitch);
 *  2. per customer: chatbot.ai.limits.per_minute_user (6) and per_day_user
 *     (40; per_day_anonymous, 10, for a +888 or missing phone) → LIMITED,
 *     with notify = true at most once per limiter window (the caller sends
 *     a short "please ask a manager" reply with the hand-off button, never
 *     an automatic hand-off); later messages in the window get the rule reply;
 *  3. global per minute (60) → DENIED (rule reply);
 *  4. today's caps, read only (RecordAiUsage::withinCaps; the job reserves
 *     atomically) → DENIED, with the once-a-day cap alert (capReached);
 *  5. pending AI jobs (AI-17): customer turns of the last PENDING_MINUTES
 *     without an answer row, counted in the database (exact, nothing to
 *     release, rolled back with the webhook) → DENIED.
 */
class GuardAiRequest
{
    public const ALLOWED = 'allowed';

    public const LIMITED = 'limited';

    public const DENIED = 'denied';

    private const PENDING_MINUTES = 10;

    public function __construct(private readonly RecordAiUsage $usage) {}

    /**
     * @return array{decision: self::ALLOWED|self::LIMITED|self::DENIED, notify: bool}
     */
    public function handle(ChatChannel $channel, TelegramUser $user): array
    {
        if (! $user->mayUseAi() || ! AiSwitch::isEnabled($channel)) {
            return $this->result(self::DENIED);
        }

        $usage = AiUsageChannel::fromChatChannel($channel);
        $limits = (array) config('chatbot.ai.limits');
        $perDay = $this->isAnonymous($user) ? (int) $limits['per_day_anonymous'] : (int) $limits['per_day_user'];
        $day = str_replace('-', '', RecordAiUsage::day());

        foreach ([
            "chat-ai:user:minute:{$user->id}" => [(int) $limits['per_minute_user'], 60],
            "chat-ai:user:day:{$day}:{$user->id}" => [$perDay, 86400],
        ] as $key => [$max, $decay]) {
            if (RateLimiter::hit($key, $decay) > $max) {
                $this->usage->capped($usage);

                return $this->result(self::LIMITED, Cache::add($key.':noticed', true, max(1, RateLimiter::availableIn($key))));
            }
        }

        if (RateLimiter::hit('chat-ai:global:minute', 60) > (int) $limits['per_minute_global']) {
            $this->usage->capped($usage);

            return $this->result(self::DENIED);
        }

        if (! $this->usage->withinCaps($usage)) {
            $this->usage->capReached($usage);

            return $this->result(self::DENIED);
        }

        if ($this->pending($channel) >= (int) $limits['pending_jobs']) {
            $this->usage->capped($usage);

            return $this->result(self::DENIED);
        }

        return $this->result(self::ALLOWED);
    }

    /**
     * Customer turns still waiting for their AI job: recent, no answer row yet.
     */
    private function pending(ChatChannel $channel): int
    {
        return ChatAiTurn::query()
            ->where('channel', $channel->value)
            ->where('role', ChatAiRole::User->value)
            ->where('created_at', '>=', now()->subMinutes(self::PENDING_MINUTES))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('chat_ai_turns as answers')
                ->whereColumn('answers.answers_turn_id', 'chat_ai_turns.id'))
            ->count();
    }

    /**
     * Telegram anonymous numbers (+888) and customers without a phone get the lower daily cap.
     */
    private function isAnonymous(TelegramUser $user): bool
    {
        $digits = (string) preg_replace('/\D/', '', (string) $user->phone);

        return $digits === '' || str_starts_with($digits, '888');
    }

    /**
     * @param  self::ALLOWED|self::LIMITED|self::DENIED  $decision
     * @return array{decision: self::ALLOWED|self::LIMITED|self::DENIED, notify: bool}
     */
    private function result(string $decision, bool $notify = false): array
    {
        return ['decision' => $decision, 'notify' => $notify];
    }
}
