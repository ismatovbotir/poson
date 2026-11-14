<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\WorkingHours;

/**
 * Build the AI prompt for one customer turn, call the model and filter the
 * answer (plan 30 §5, §6, AI-6, AI-9, AI-11 – AI-13). Writes nothing; the
 * job (AnswerChatWithAi) stores, counts and delivers.
 *
 * prepare() — system blocks, stable first:
 *   1. AiPrompt::brand() + the Admin instructions (cache breakpoint 1);
 *   2. BuildAiKnowledge, the public <knowledge> of the locale and channel
 *      (cache breakpoint 2);
 *   3. volatile: date and time (Asia/Tashkent), the reply language, whether
 *      managers are available, the weak rule's answer as a hint, <products>
 *      (product candidates arrive in phase B: FindAiProducts; empty now).
 * messages = the history window (phase B: AiHistory; empty now) + the
 * redacted customer message inside <customer_message>. Only ChatRedactor
 * output is sent: no TelegramUser field, no metadata.user_id (AI-6).
 *
 * answer() — the call (outside any transaction, by the caller), then:
 * refusal → refused; AiUnavailable / AiFailed / empty → failed; filter hit →
 * blocked; max_tokens → truncated to the last full sentence; else answered.
 */
class AnswerWithAi
{
    public const AUTH_FAILED = 'auth_failed';

    public function __construct(
        private readonly BuildAiKnowledge $knowledge,
        private readonly RuleIndex $index,
    ) {}

    public function prepare(ChatContext $context, string $redactedText, ?int $hintRuleId = null): AiRequest
    {
        $knowledge = $this->knowledge->handle($context->locale, $context->channel);
        $message = AiPrompt::field($redactedText, (int) config('chatbot.ai.message_max', 1000));

        return new AiRequest(
            system: [
                ['text' => AiPrompt::brand(ChatSettings::all()['ai']['instructions']), 'cache' => true],
                ['text' => $knowledge, 'cache' => true],
                ['text' => $this->volatile($context, $hintRuleId), 'cache' => false],
            ],
            messages: [
                ...$this->history($context),
                ['role' => 'user', 'content' => "<customer_message>\n{$message}\n</customer_message>"],
            ],
            maxTokens: (int) config('chatbot.ai.max_tokens', 400),
        );
    }

    /**
     * sha256 of the prompt version, the Admin instructions and the knowledge block (never the prompt itself).
     */
    public function promptHash(AiRequest $request): string
    {
        return hash('sha256', AiPrompt::VERSION."\n".ChatSettings::all()['ai']['instructions']."\n".hash('sha256', $request->system[1]['text'] ?? ''));
    }

    public function answer(ChatModel $model, AiRequest $request, ChatChannel $channel): AiAnswer
    {
        $hash = $this->promptHash($request);

        try {
            $result = $model->complete($request);
        } catch (AiUnavailable) {
            return new AiAnswer(ChatAiStatus::Failed, '', null, 'unavailable', $hash);
        } catch (AiFailed $failure) {
            // M3: a rejected key pauses AI answers (AiSwitch) instead of failing on every message.
            if (in_array($failure->status, [401, 403], true)) {
                AiSwitch::markAuthFailed();

                return new AiAnswer(ChatAiStatus::Failed, '', null, self::AUTH_FAILED, $hash);
            }

            return new AiAnswer(ChatAiStatus::Failed, '', null, mb_substr($failure->errorClass, 0, 40), $hash);
        }

        if ($result->isRefusal()) {
            return new AiAnswer(ChatAiStatus::Refused, '', $result, 'refusal', $hash);
        }

        $filtered = $this->filter($channel)->apply($result->text, $result->isTruncated());

        return match (true) {
            $filtered['blocked'] === 'empty' => new AiAnswer(ChatAiStatus::Failed, '', $result, 'empty', $hash),
            $filtered['blocked'] !== null => new AiAnswer(ChatAiStatus::Blocked, '', $result, $filtered['blocked'], $hash),
            $result->isTruncated() => new AiAnswer(ChatAiStatus::Truncated, $filtered['text'], $result, null, $hash),
            default => new AiAnswer(ChatAiStatus::Answered, $filtered['text'], $result, null, $hash),
        };
    }

    /**
     * The customer-facing reply of a delivered answer: the fixed localized
     * "AI assistant" marker added by the server (AI-13), the text and the
     * hand-off button. The model never produces buttons.
     */
    public function reply(AiAnswer $answer, string $locale): ChatReply
    {
        return new ChatReply(
            __('chatbot.ai.marker', [], $locale)."\n\n".$answer->text,
            [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)]],
            null,
            ChatReply::DONE,
        );
    }

    private function volatile(ChatContext $context, ?int $hintRuleId): string
    {
        $now = now(WorkingHours::TIMEZONE);
        $language = ['uz' => 'Uzbek (Latin script)', 'ru' => 'Russian', 'en' => 'English'][$context->locale] ?? 'Uzbek (Latin script)';
        $lines = [
            '<context>',
            'Now: '.$now->format('Y-m-d H:i').' (Asia/Tashkent)',
            'Reply language: '.$language,
            'Managers available now: '.(WorkingHours::isOpen() ? 'yes' : 'no (they reply during working hours)'),
            '</context>',
        ];

        $hint = $this->hint($hintRuleId, $context->locale);

        if ($hint !== '') {
            $lines[] = "A ready Poson answer that may be related (use it only if it fits):\n<knowledge>\n{$hint}\n</knowledge>";
        }

        $lines[] = "<products>\n".implode("\n", $this->products($context))."\n</products>";

        return implode("\n", $lines);
    }

    private function hint(?int $ruleId, string $locale): string
    {
        if ($ruleId === null) {
            return '';
        }

        $rule = $this->index->load()['rules'][$ruleId] ?? null;

        if ($rule === null) {
            return '';
        }

        $answer = (string) ($rule['answer'][$locale] ?? '') !== '' ? $rule['answer'][$locale] : (string) ($rule['answer']['uz'] ?? '');

        return AiPrompt::field($answer, 500);
    }

    /**
     * Earlier turns of this conversation (phase B: AiHistory, plan 30 §7). Empty in phase A.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    protected function history(ChatContext $context): array
    {
        return [];
    }

    /**
     * Product candidate lines (phase B: FindAiProducts, plan 30 §6). Empty in phase A.
     *
     * @return list<string>
     */
    protected function products(ChatContext $context): array
    {
        return [];
    }

    /**
     * Retail prices the answer may name (phase B: the candidates' formatted Retail prices). None in phase A.
     *
     * @return list<string>
     */
    protected function allowedAmounts(): array
    {
        return [];
    }

    private function filter(ChatChannel $channel): AiOutputFilter
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosts = $host === '' ? [] : [$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host];
        $contacts = $this->knowledge->contacts();

        return new AiOutputFilter($hosts, $contacts['phones'], $contacts['emails'], $this->allowedAmounts());
    }
}
