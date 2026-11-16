<?php

namespace App\Actions\Chat;

use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Models\ChatRule;
use App\Models\User;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatText;
use App\Support\Chat\Engine;
use App\Support\Chat\LookupNumber;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\Gate;

/**
 * The test console's dry run (plan 28 §5, CB-11): what would the bot do with
 * this phrase? Needs chatbot.rules.
 *
 * Writes nothing: no stats, no unmatched row, no chat, no queue job, no HTTP
 * call. Rules that would look something up, start a lead dialog, search
 * products or hand the chat to a manager are NOT run: the result only says
 * "would run X" (so no order, repair or customer table is read). Plain answers,
 * FAQ, company info, links and the menu are rendered with the engine's own
 * skills (they read only public content), so the editor sees the real reply.
 *
 * Result shape:
 *   normalized: string, locale, channel, min_score: int,
 *   matched: bool, rule: {id, key, name, action, action_label}|null, score: int|null,
 *   keywords: list<{keyword, type, weight}>, priority: int|null,
 *   would_run: string|null (action label or "product search" for model-like text),
 *   reply: {text: string, buttons: list<{label, kind}>}|null, fallback: bool,
 *   site: list<{n, label, title, url}> — the website passages the AI would get in its <site> block
 *   (plan 33 K-14: FindAiKnowledge, the same public re-check; never a model call),
 *   ai: {reason: string, enabled: bool}|null — "would ask AI" (plan 30, AI-24):
 *   the engine's reason, never a model call (the model is never resolved here)
 */
class TestChatPhrase
{
    /**
     * Actions that are only reported, never executed here.
     */
    private const NOT_RUN = [ChatAction::ProductSearch, ChatAction::OrderStatus, ChatAction::RepairStatus, ChatAction::LeadDialog, ChatAction::Handoff];

    public function __construct(private readonly Engine $engine, private readonly RuleIndex $index, private readonly FindAiKnowledge $knowledge) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $actor, string $text, string $locale, ChatChannel $channel): array
    {
        Gate::forUser($actor)->authorize('viewAny', ChatRule::class);

        $locale = in_array($locale, config('app.supported_locales'), true) ? $locale : 'uz';
        $context = new ChatContext($channel, $locale, $text);
        $normalized = $context->normalized();
        $aiReason = $this->engine->aiReason($context);
        $hits = $this->knowledge->handle($text, $locale);

        $result = [
            'normalized' => $normalized,
            'locale' => $locale,
            'channel' => $channel->value,
            'min_score' => (int) config('chatbot.min_score', 10),
            'matched' => false,
            'rule' => null,
            'score' => null,
            'priority' => null,
            'keywords' => [],
            'would_run' => null,
            'reply' => null,
            'fallback' => false,
            'site' => array_map(fn (AiKnowledgeHit $hit, int $index): array => [
                'n' => $index + 1,
                'label' => (string) __('enums.ai_knowledge_source.'.$hit->type->value, [], $locale),
                'title' => $hit->title,
                'url' => $hit->url,
            ], $hits, array_keys($hits)),
            'ai' => $aiReason === null ? null : [
                'reason' => __('chatbot_admin.console.ai_reasons.'.$aiReason),
                'enabled' => AiSwitch::isEnabled($channel),
            ],
        ];

        $number = LookupNumber::parse($text);

        if ($number !== null) {
            return [...$result, 'would_run' => ($number['kind'] === LookupNumber::ORDER ? ChatAction::OrderStatus : ChatAction::RepairStatus)->label()];
        }

        $data = $this->index->load();

        if ($data === null) {
            return [...$result, 'fallback' => true, 'reply' => $this->reply($this->engine->respond($context))];
        }

        $match = $this->engine->match($data, $context, $normalized);

        if ($match === null) {
            if (ChatText::looksLikeModel($normalized)) {
                return [...$result, 'would_run' => __('chatbot_admin.console.would_search')];
            }

            return [...$result, 'fallback' => true, 'reply' => $this->reply($this->engine->respond($context))];
        }

        $rule = $data['rules'][$match->ruleId];
        $action = ChatAction::tryFrom($rule['action']);
        $result = [
            ...$result,
            'matched' => true,
            'rule' => ['id' => $rule['id'], 'key' => $rule['key'], 'name' => $rule['name'], 'action' => $rule['action'], 'action_label' => $action?->label() ?? $rule['action']],
            'score' => $match->score,
            'priority' => (int) $rule['priority'],
            'keywords' => $match->keywords,
        ];

        if ($action === null || in_array($action, self::NOT_RUN, true)) {
            return [...$result, 'would_run' => $action?->label() ?? $rule['action']];
        }

        $reply = $this->engine->respondToRule($context, $match->ruleId);

        return [...$result, 'reply' => $reply === null ? null : $this->reply($reply)];
    }

    /**
     * @return array{text: string, buttons: list<array{label: string, kind: string}>}
     */
    private function reply(ChatReply $reply): array
    {
        return [
            'text' => $reply->text,
            'buttons' => array_map(fn (array $button): array => ['label' => (string) $button['label'], 'kind' => (string) $button['type']], $reply->buttons),
        ];
    }
}
