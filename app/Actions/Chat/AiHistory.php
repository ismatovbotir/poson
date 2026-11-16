<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
use App\Support\Chat\AiPrompt;
use Illuminate\Database\Eloquent\Builder;

/**
 * The earlier turns of one AI conversation sent with a new question (plan
 * 30 §7, AI-B6 – AI-B8). Reads only; one query.
 *
 *  - Same customer AND channel, only turns before the current one, created
 *    within chatbot.ai.history.minutes (30) and after ai_context_at (set on
 *    Menu, /start, cancel, hand-off, manager mode, staff reply, ban): the
 *    window starts at max(now − 30 min, ai_context_at).
 *  - Only customer turns whose assistant row was answered or truncated, each
 *    followed by that answer (answers_turn_id): blocked, refused, failed,
 *    capped, superseded and disabled pairs never come back. The stored
 *    answer never holds the server's "AI assistant" marker (added at send).
 *  - The newest pairs first up to chatbot.ai.history.turns (10) messages and
 *    chatbot.ai.history.chars (4,000) characters, then oldest first, so
 *    roles strictly alternate (user, assistant, …) and the current question
 *    follows as the last user message. Every text goes through
 *    AiPrompt::field and customer text stays inside <customer_message>.
 */
class AiHistory
{
    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function handle(TelegramUser $user, ChatAiTurn $current): array
    {
        $config = (array) config('chatbot.ai.history');
        $maxMessages = max(0, (int) ($config['turns'] ?? 10));
        $maxChars = max(0, (int) ($config['chars'] ?? 4000));
        $since = now()->subMinutes(max(1, (int) ($config['minutes'] ?? 30)));

        if ($maxMessages < 2 || $maxChars === 0) {
            return [];
        }

        $context = $user->ai_context_at;

        $turns = ChatAiTurn::query()
            ->select(['id', 'telegram_user_id', 'channel', 'role', 'text', 'created_at'])
            ->where('telegram_user_id', $user->id)
            ->where('channel', $current->channel->value)
            ->where('role', ChatAiRole::User->value)
            ->where('id', '<', $current->id)
            ->where('created_at', '>=', $since)
            ->when($context !== null, fn (Builder $query) => $query->where('created_at', '>', $context))
            ->whereHas('answer', fn (Builder $query) => $query->whereIn('status', [ChatAiStatus::Answered->value, ChatAiStatus::Truncated->value]))
            ->with(['answer' => fn ($query) => $query->select(['id', 'answers_turn_id', 'text', 'status'])])
            ->orderByDesc('id')
            ->limit(intdiv($maxMessages, 2))
            ->get();

        $pairs = [];
        $chars = 0;

        foreach ($turns as $turn) {
            $question = "<customer_message>\n".AiPrompt::field($turn->text, (int) config('chatbot.ai.message_max', 1000))."\n</customer_message>";
            $answer = AiPrompt::field((string) $turn->answer?->text, (int) config('chatbot.ai.answer_max', 1000));

            if ($answer === '') {
                continue;
            }

            $chars += mb_strlen($question) + mb_strlen($answer);

            if ($chars > $maxChars) {
                break;
            }

            $pairs[] = [['role' => 'user', 'content' => $question], ['role' => 'assistant', 'content' => $answer]];
        }

        return array_merge(...array_reverse($pairs));
    }
}
