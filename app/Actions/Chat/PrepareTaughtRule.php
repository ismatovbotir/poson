<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\KeywordMatch;
use App\Models\ChatAiTurn;
use App\Models\ChatUnmatched;
use App\Support\Chat\ChatText;

/**
 * The pre-fill of "Teach from AI answer" (plan 30 §16, AI-B11, AI-B17).
 * Reads only; nothing is saved here — the editor reviews the rule form and
 * saves it (SaveChatRule after CheckTaughtRule). No rule is ever created
 * automatically.
 *
 *  - The answer: the server picks the latest delivered (answered, then
 *    truncated) assistant turn linked to the phrase, preferring one asked
 *    without earlier AI turns of that customer in the history window (so the
 *    answer does not lean on a conversation). Its stored text is already
 *    redacted and filtered; the server's "AI assistant" marker is not stored.
 *  - Keywords: the words of the phrase's normalized form (ChatText),
 *    stopwords of every locale and short words (under 3 characters unless
 *    they hold a digit) dropped, at most chatbot.ai.teach_keywords (10), each
 *    a "word" keyword in the phrase's locale with the default weight (never
 *    "exact").
 *
 * Null when the phrase is resolved or has no delivered AI answer.
 */
class PrepareTaughtRule
{
    public const DEFAULT_WEIGHT = 10;

    private const CANDIDATES = 5;

    /**
     * @return array{turn_id: int, locale: string, answer: string, name: string, key: string, keywords: list<array{phrase: string, locale: string, match_type: string, weight: int}>}|null
     */
    public function handle(ChatUnmatched $unmatched): ?array
    {
        if ($unmatched->resolved_at !== null) {
            return null;
        }

        $turn = $this->answer($unmatched);

        if ($turn === null) {
            return null;
        }

        $locale = in_array($turn->locale, config('app.supported_locales'), true) ? $turn->locale : $unmatched->locale;
        $words = self::keywords((string) $unmatched->normalized);

        return [
            'turn_id' => $turn->id,
            'locale' => $locale,
            'answer' => $turn->text,
            'name' => mb_strimwidth(trim(str_replace(['<', '>'], '', (string) $unmatched->sample)), 0, 60, '…'),
            'key' => self::key($unmatched->id, $words),
            'keywords' => array_map(fn (string $word): array => [
                'phrase' => $word,
                'locale' => $unmatched->locale,
                'match_type' => KeywordMatch::Word->value,
                'weight' => self::DEFAULT_WEIGHT,
            ], $words),
        ];
    }

    /**
     * Keyword words of a phrase (see the class comment).
     *
     * @return list<string>
     */
    public static function keywords(string $phrase): array
    {
        $stopwords = [];

        foreach ((array) config('chatbot.ai.stopwords', []) as $list) {
            foreach ((array) $list as $word) {
                $stopwords[ChatText::normalize((string) $word)] = true;
            }
        }

        $words = [];

        foreach (ChatText::tokens(ChatText::normalize($phrase)) as $word) {
            if (isset($stopwords[$word]) || mb_strlen($word) < (preg_match('/\d/', $word) === 1 ? 2 : 3)) {
                continue;
            }

            $words[$word] = true;
        }

        return array_slice(array_keys($words), 0, max(1, (int) config('chatbot.ai.teach_keywords', 10)));
    }

    /**
     * A rule key from the phrase id and its Latin keyword words ("ai_12_zebra_printer"), at most 60 characters.
     *
     * @param  list<string>  $words
     */
    public static function key(int $unmatchedId, array $words): string
    {
        $latin = array_filter($words, fn (string $word): bool => strspn($word, 'abcdefghijklmnopqrstuvwxyz0123456789') === strlen($word));

        return rtrim(substr(implode('_', ['ai', $unmatchedId, ...$latin]), 0, 60), '_');
    }

    private function answer(ChatUnmatched $unmatched): ?ChatAiTurn
    {
        $candidates = ChatAiTurn::query()
            ->select(['id', 'telegram_user_id', 'answers_turn_id', 'locale', 'text', 'status', 'created_at'])
            ->where('chat_unmatched_id', $unmatched->id)
            ->where('role', ChatAiRole::Assistant->value)
            ->whereIn('status', [ChatAiStatus::Answered->value, ChatAiStatus::Truncated->value])
            ->where('text', '!=', '')
            ->orderByRaw('case when status = ? then 0 else 1 end', [ChatAiStatus::Answered->value])
            ->orderByDesc('id')
            ->limit(self::CANDIDATES)
            ->get();

        return $candidates->first(fn (ChatAiTurn $turn): bool => ! $this->hadHistory($turn)) ?? $candidates->first();
    }

    /**
     * Whether the customer had a delivered AI answer shortly before this question (it was then sent as history).
     */
    private function hadHistory(ChatAiTurn $turn): bool
    {
        return ChatAiTurn::query()
            ->where('telegram_user_id', $turn->telegram_user_id)
            ->where('role', ChatAiRole::Assistant->value)
            ->whereIn('status', [ChatAiStatus::Answered->value, ChatAiStatus::Truncated->value])
            ->where('id', '<', (int) $turn->answers_turn_id)
            ->where('created_at', '>=', $turn->created_at->copy()->subMinutes(max(1, (int) config('chatbot.ai.history.minutes', 30))))
            ->exists();
    }
}
