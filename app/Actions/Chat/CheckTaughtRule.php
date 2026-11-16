<?php

namespace App\Actions\Chat;

use App\Enums\KeywordMatch;
use Illuminate\Validation\ValidationException;

/**
 * The extra checks of a rule taught from an AI answer (plan 30 §16, AI-B11),
 * run by the rule form BEFORE SaveChatRule (which still applies its own
 * plain-text, brand and link rules) whenever the form was opened with a
 * server-picked AI answer. Pure checks, nothing is written.
 *
 *  - Every locale's answer goes through the AI output filter with NO allowed
 *    amounts: links to other hosts, @handles, foreign emails, phone or card
 *    numbers, any money amount, "%" and the AI-only brand wording block it
 *    (the company's own phones, emails and site links pass); also bare or
 *    messenger handles ("poson_support_bot", "Telegram: posonservice"),
 *    spelled-out domains ("posonuz dot shop"), percentage / discount words
 *    and payment instructions (M1, M2, L4). The whole text is checked
 *    (AiOutputFilter::check(), no answer_max cut, L3).

 *  - Keywords: at most chatbot.ai.teach_keywords (10), never "exact", weight
 *    at most the default (10), so a taught rule cannot take over the bot's
 *    strong direct answers.
 *
 * Error keys mirror SaveChatRule ("answer.ru", "keywords.0").
 */
class CheckTaughtRule
{
    public function __construct(private readonly BuildAiKnowledge $knowledge) {}

    /**
     * @param  array<string, mixed>  $data  The SaveChatRule input
     *
     * @throws ValidationException
     */
    public function handle(array $data): void
    {
        $errors = [];
        $filter = $this->knowledge->outputFilter();
        $answers = is_array($data['answer'] ?? null) ? $data['answer'] : [];

        foreach (config('app.supported_locales') as $locale) {
            $text = is_string($answers[$locale] ?? null) ? trim($answers[$locale]) : '';

            if ($text !== '' && $filter->check($text) !== null) {
                $errors["answer.{$locale}"] = __('chatbot_admin.rules.teach.answer_blocked');
            }
        }

        $keywords = is_array($data['keywords'] ?? null) ? array_values($data['keywords']) : [];
        $max = max(1, (int) config('chatbot.ai.teach_keywords', 10));

        if (count($keywords) > $max) {
            $errors['keywords'] = __('chatbot_admin.rules.teach.too_many_keywords', ['max' => $max]);
        }

        foreach ($keywords as $index => $keyword) {
            $type = is_array($keyword) ? KeywordMatch::tryFrom((string) ($keyword['match_type'] ?? '')) : null;

            if ($type === KeywordMatch::Exact) {
                $errors["keywords.{$index}"] = __('chatbot_admin.rules.teach.no_exact');
            } elseif (is_array($keyword) && (int) ($keyword['weight'] ?? 0) > PrepareTaughtRule::DEFAULT_WEIGHT) {
                $errors["keywords.{$index}"] = __('chatbot_admin.rules.teach.weight_max', ['max' => PrepareTaughtRule::DEFAULT_WEIGHT]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
