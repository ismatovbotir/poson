<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
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
 *      managers are available, the weak rule's answer as a hint, and
 *      <products>: the candidates of FindAiProducts (products(), phase B).
 * messages = the history window (history(): AiHistory) + the redacted
 * customer message inside <customer_message>. Only ChatRedactor output is
 * sent: no TelegramUser field, no metadata.user_id (AI-6).
 *
 * The candidates' Retail prices are the only amounts the answer may name
 * (AiOutputFilter, AI-B1 – AI-B3); reply() adds a product button for at
 * most chatbot.ai.products.buttons candidates the answer names.
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
        private readonly FindAiProducts $finder,
        private readonly AiHistory $conversation,
    ) {}

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history  Earlier turns (history())
     * @param  list<AiProductCandidate>  $products  Candidates (products())
     */
    public function prepare(ChatContext $context, string $redactedText, ?int $hintRuleId = null, array $history = [], array $products = []): AiRequest
    {
        $knowledge = $this->knowledge->handle($context->locale, $context->channel);
        $message = AiPrompt::field($redactedText, (int) config('chatbot.ai.message_max', 1000));

        return new AiRequest(
            system: [
                ['text' => AiPrompt::brand(ChatSettings::all()['ai']['instructions']), 'cache' => true],
                ['text' => $knowledge, 'cache' => true],
                ['text' => $this->volatile($context, $hintRuleId, $products), 'cache' => false],
            ],
            messages: [
                ...$history,
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

    /**
     * @param  list<AiProductCandidate>  $products  The candidates of the request (their prices may be named)
     */
    public function answer(ChatModel $model, AiRequest $request, ChatChannel $channel, array $products = []): AiAnswer
    {
        $hash = $this->promptHash($request);
        $ids = array_map(fn (AiProductCandidate $product): int => $product->id, $products);

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

        $filtered = $this->filter($products)->apply($result->text, $result->isTruncated());

        return match (true) {
            $filtered['blocked'] === 'empty' => new AiAnswer(ChatAiStatus::Failed, '', $result, 'empty', $hash, $ids),
            $filtered['blocked'] !== null => new AiAnswer(ChatAiStatus::Blocked, '', $result, $filtered['blocked'], $hash, $ids),
            $result->isTruncated() => new AiAnswer(ChatAiStatus::Truncated, $filtered['text'], $result, null, $hash, $ids),
            default => new AiAnswer(ChatAiStatus::Answered, $filtered['text'], $result, null, $hash, $ids),
        };
    }

    /**
     * The customer-facing reply of a delivered answer: the fixed localized
     * "AI assistant" marker added by the server (AI-13), the text, a product
     * button for each candidate the answer names (at most
     * chatbot.ai.products.buttons; stored server-side by the adapter, the
     * product and its price are read again at the tap, AI-B9) and the
     * hand-off button. The model never produces buttons.
     *
     * @param  list<AiProductCandidate>  $products
     */
    public function reply(AiAnswer $answer, string $locale, array $products = []): ChatReply
    {
        $buttons = [];

        foreach ($products as $product) {
            if (count($buttons) >= (int) config('chatbot.ai.products.buttons', 3)) {
                break;
            }

            if (in_array($product->id, $answer->productIds, true) && $product->isNamedIn($answer->text)) {
                $buttons[] = ['type' => 'product', 'label' => $product->label, 'id' => $product->id];
            }
        }

        return new ChatReply(
            __('chatbot.ai.marker', [], $locale)."\n\n".$answer->text,
            [...$buttons, ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)]],
            null,
            ChatReply::DONE,
        );
    }

    /**
     * Product candidates for the question (FindAiProducts; the redacted turn text).
     *
     * @return list<AiProductCandidate>
     */
    public function products(ChatContext $context, string $redactedText): array
    {
        return $this->finder->handle($redactedText, $context->locale);
    }

    /**
     * The earlier turns of this conversation (AiHistory, plan 30 §7).
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function history(TelegramUser $user, ChatAiTurn $turn): array
    {
        return $this->conversation->handle($user, $turn);
    }

    /**
     * @param  list<AiProductCandidate>  $products
     */
    private function volatile(ChatContext $context, ?int $hintRuleId, array $products): string
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

        $lines[] = "<products>\n".implode("\n", array_map(fn (AiProductCandidate $product): string => $product->line, $products))."\n</products>";

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
     * @param  list<AiProductCandidate>  $products
     */
    private function filter(array $products): AiOutputFilter
    {
        return $this->knowledge->outputFilter(array_map(fn (AiProductCandidate $product) => $product->price, $products));
    }
}
