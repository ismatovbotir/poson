<?php

namespace App\Support\Chat;

use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\LeadFlow;
use App\Rules\BrandSafeText;
use App\Support\Chat\Skills\CompanyInfoSkill;
use App\Support\Chat\Skills\FaqSkill;
use App\Support\Chat\Skills\LinkSkill;
use App\Support\Chat\Skills\MenuSkill;

/**
 * The rule engine (plan 28 §2, CB-1, CB-2, CB-9): text in, ChatReply out.
 *
 * Matching (match()) is pure: it reads only the compiled rules from the cache
 * (zero database queries on a warm cache) and compares with string functions,
 * never regular expressions, so no input and no admin keyword can be a
 * pattern. Skills may read the database (FAQ, company data, pages) to build
 * the answer; nothing here writes anything, makes an HTTP call or touches an
 * AI service. Recording stats, unmatched phrases, mode changes and sending
 * are the callers' job (HandleChatInput).
 *
 * Score = sum of the weights of a rule's matched keywords + its priority.
 * The best rule wins when its score reaches config('chatbot.min_score'); a tie
 * goes to the higher priority, then a topic rule over a menu/greeting rule,
 * then the lower id. A keyword with a locale
 * counts only for that locale; rules count only on their channels.
 * No match: model-like text (letters and digits, like "ZD421") gets the
 * product search marker, anything else the fallback with the menu.
 *
 * Searches, status lookups and lead dialogs are not run here (C3, C4,
 * CB-28): the engine cannot tell who asks, so it returns an outcome marker
 * (ChatReply::SEARCH, ORDER_STATUS, REPAIR_STATUS, LEAD) and
 * HandleChatInput runs it for the locked Telegram user. A whole message
 * that is an order or repair number (LookupNumber) is routed to the lookup
 * before any rule. On the website lookups are never offered (plan 28 §15):
 * the reply asks for a manager.
 *
 * Settings (CB-31): respond() reads ChatSettings (one memoized query per
 * request or job) for the fallback and greeting texts; match() reads nothing.
 *
 * AI answers (plan 30 §2.1, §2.2, P5): with $aiEnabled the engine still
 * builds its normal reply, and returns it wrapped in a ChatReply::AI marker
 * when the model should answer instead: a weak "answer"/"faq" hit, model-like
 * text, or no match. A rule answers directly (never AI) when its action is
 * deterministic or has side effects (hand-off, status lookups, lead dialog,
 * menu, product search, company info, link), when a keyword matched exactly
 * (the whole message), or when the score reaches chatbot.ai.direct_min_score.
 * One exception, the soft match (plan 33 A2-2, A2-S16): a weak hit of a
 * product-search rule or a quote-request lead dialog (no exact keyword, score
 * below direct_min_score) on a message of at least chatbot.ai.soft_min_words
 * words goes to AI too, while chatbot.ai.soft_match is on; the AI answer
 * then carries that rule's button (softRuleButton()), so the deterministic
 * search or dialog is one tap away and no dialog input ever reaches the
 * model. Callback dialogs, menu keys, pressed buttons (respondToRule,
 * respondToKey) and short messages are never soft.
 * Lookup numbers, an unavailable index and empty text (emoji, stickers)
 * never reach AI. With $aiEnabled false the result is exactly as before. The
 * engine never calls the model: HandleChatInput queues the job.
 */
class Engine
{
    /**
     * Actions that may be answered by AI on a weak hit; every other action always answers directly.
     */
    private const AI_ACTIONS = [ChatAction::Answer, ChatAction::Faq];

    public function __construct(
        private readonly RuleIndex $index,
        private readonly FaqSkill $faq,
        private readonly CompanyInfoSkill $companyInfo,
        private readonly LinkSkill $link,
        private readonly MenuSkill $menu,
    ) {}

    public function respond(ChatContext $context, bool $aiEnabled = false): ChatReply
    {
        $number = LookupNumber::parse($context->text);

        if ($number !== null) {
            return $this->lookup($context, $number['kind'], null, $number['number']);
        }

        $data = $this->index->load();

        if ($data === null) {
            return $this->unavailable($context);
        }

        $normalized = $context->normalized();
        $match = $this->match($data, $context, $normalized);

        if ($match !== null) {
            $reply = $this->runRule($data, $data['rules'][$match->ruleId], $context);
        } elseif (ChatText::looksLikeModel($normalized)) {
            $reply = new ChatReply(
                __('chatbot.search.prompt', [], $context->locale),
                [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
                null,
                ChatReply::SEARCH,
            );
        } else {
            $reply = new ChatReply(
                ChatSettings::text('fallback', $context->locale) ?? __('chatbot.fallback', [], $context->locale),
                [...$this->menu->buttons($data, $context), ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
                null,
                $normalized === '' ? ChatReply::FALLBACK : ChatReply::UNMATCHED,
            );
        }

        if (! $aiEnabled || $this->aiReasonFor($data, $match, $normalized) === null) {
            return $reply;
        }

        return new ChatReply($reply->text, $reply->buttons, $match?->ruleId, ChatReply::AI, null, $reply);
    }

    /**
     * Why the model would answer this message if AI were on (the test
     * console's "would ask AI"): weak_match, model_like or no_match; null
     * when a rule, a lookup or the fallback answers directly.
     */
    public function aiReason(ChatContext $context): ?string
    {
        if (LookupNumber::parse($context->text) !== null) {
            return null;
        }

        $data = $this->index->load();

        if ($data === null) {
            return null;
        }

        $normalized = $context->normalized();

        return $this->aiReasonFor($data, $this->match($data, $context, $normalized), $normalized);
    }

    /**
     * @param  array{rules: array<int, array<string, mixed>>}  $data
     */
    private function aiReasonFor(array $data, ?ChatMatch $match, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        if ($match !== null) {
            return $this->answersDirectly($data['rules'][$match->ruleId], $match, $normalized) ? null : 'weak_match';
        }

        return ChatText::looksLikeModel($normalized) ? 'model_like' : 'no_match';
    }

    /**
     * A rule hit the model never overrides (§2.2): a deterministic or
     * side-effect action, an exact keyword (the whole message), or a strong
     * score; a soft hit (isSoftHit()) is the one deterministic action that may go to AI.
     *
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    private function answersDirectly(array $rule, ChatMatch $match, string $normalized): bool
    {
        if ($this->isSoftHit($rule, $match, $normalized)) {
            return false;
        }

        if (! in_array(ChatAction::tryFrom($rule['action']), self::AI_ACTIONS, true)) {
            return true;
        }

        foreach ($match->keywords as $hit) {
            if ($hit['type'] === 'exact') {
                return true;
            }
        }

        return $match->score >= (int) config('chatbot.ai.direct_min_score', 30);
    }

    /**
     * A weak hit of a product-search or quote-request rule on a message of
     * soft_min_words or more words, while the soft match is on (A2-2, A2-S16).
     *
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    private function isSoftHit(array $rule, ChatMatch $match, string $normalized): bool
    {
        if (! config('chatbot.ai.soft_match', true) || ! self::isSoftRule($rule)) {
            return false;
        }

        foreach ($match->keywords as $hit) {
            if ($hit['type'] === 'exact') {
                return false;
            }
        }

        return $match->score < (int) config('chatbot.ai.direct_min_score', 30)
            && count(explode(' ', $normalized)) >= max(1, (int) config('chatbot.ai.soft_min_words', 3));
    }

    /**
     * A product-search rule or a lead dialog with the quote flow (never the callback flow).
     *
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    private static function isSoftRule(array $rule): bool
    {
        return match (ChatAction::tryFrom($rule['action'])) {
            ChatAction::ProductSearch => true,
            ChatAction::LeadDialog => ($rule['params']['flow'] ?? null) === LeadFlow::Quote->value,
            default => false,
        };
    }

    /**
     * The button of a soft rule under an AI answer (A2-S7): the rule must
     * still be active, on the channel and a product-search or quote rule; its
     * menu label, cut to 40 characters and brand-safe. A tap goes through the
     * offered-rule check to respondToRule(), which is deterministic. Null
     * otherwise.
     *
     * @return array{type: string, label: string, id: int}|null
     */
    public function softRuleButton(ChatContext $context, int $ruleId): ?array
    {
        $data = $this->index->load();
        $rule = $data['rules'][$ruleId] ?? null;

        if ($rule === null || ! MenuSkill::visibleOn($rule, $context->channel) || ! self::isSoftRule($rule)) {
            return null;
        }

        $labels = is_array($rule['params']['label'] ?? null) ? $rule['params']['label'] : [];
        $label = AiPrompt::field(trim((string) ($labels[$context->locale] ?? $labels['uz'] ?? $rule['name'])), (int) config('chatbot.label_max', 40));

        return $label === '' || BrandSafeText::violates($label) || AiOutputFilter::violatesBrand($label) ? null : ['type' => 'rule', 'label' => $label, 'id' => $ruleId];
    }

    /**
     * The winning rule for a normalized message, or null below min_score.
     * Pure: no database, no writes.
     *
     * @param  array{rules: array<int, array<string, mixed>>, keywords: list<array{r: int, l: ?string, n: string, t: string, w: int}>}  $data
     */
    public function match(array $data, ChatContext $context, ?string $normalized = null): ?ChatMatch
    {
        $normalized ??= $context->normalized();

        if ($normalized === '') {
            return null;
        }

        $padded = ' '.$normalized.' ';
        $spaced = ' '.$normalized;
        $scores = [];
        $hits = [];

        foreach ($data['keywords'] as $keyword) {
            $rule = $data['rules'][$keyword['r']] ?? null;

            if ($rule === null || ! MenuSkill::visibleOn($rule, $context->channel)) {
                continue;
            }

            if ($keyword['l'] !== null && $keyword['l'] !== $context->locale) {
                continue;
            }

            $matched = match ($keyword['t']) {
                'word', 'phrase' => str_contains($padded, ' '.$keyword['n'].' '),
                'prefix' => str_contains($spaced, ' '.$keyword['n']),
                'exact' => $normalized === $keyword['n'],
                default => false,
            };

            if (! $matched) {
                continue;
            }

            $scores[$keyword['r']] = ($scores[$keyword['r']] ?? 0) + $keyword['w'];
            $hits[$keyword['r']][] = ['keyword' => $keyword['n'], 'type' => $keyword['t'], 'weight' => $keyword['w']];
        }

        $best = null;

        foreach ($scores as $ruleId => $weights) {
            $priority = (int) $data['rules'][$ruleId]['priority'];
            $score = $weights + $priority;
            // On a full tie a menu/greeting rule loses to a topic rule.
            $isMenu = $data['rules'][$ruleId]['action'] === ChatAction::Menu->value ? 1 : 0;
            $candidate = [$score, $priority, -$isMenu, -$ruleId];

            if ($best === null || $candidate > $best['rank']) {
                $best = ['id' => $ruleId, 'score' => $score, 'rank' => $candidate];
            }
        }

        if ($best === null || $best['score'] < (int) config('chatbot.min_score', 10)) {
            return null;
        }

        return new ChatMatch($best['id'], $best['score'], $hits[$best['id']]);
    }

    /**
     * The answer of one rule (a pressed button or menu entry), or null when
     * the rule is not active or not on this channel.
     */
    public function respondToRule(ChatContext $context, int $ruleId): ?ChatReply
    {
        $data = $this->index->load();

        if ($data === null) {
            return $this->unavailable($context);
        }

        $rule = $data['rules'][$ruleId] ?? null;

        return $rule === null || ! MenuSkill::visibleOn($rule, $context->channel) ? null : $this->runRule($data, $rule, $context);
    }

    /**
     * The answer of the system rule with this key, or null when missing.
     */
    public function respondToKey(ChatContext $context, string $key): ?ChatReply
    {
        $data = $this->index->load();

        if ($data === null) {
            return $this->unavailable($context);
        }

        foreach ($data['rules'] as $rule) {
            if ($rule['key'] === $key) {
                return MenuSkill::visibleOn($rule, $context->channel) ? $this->runRule($data, $rule, $context) : null;
            }
        }

        return null;
    }

    /**
     * The topic menu for the channel.
     */
    public function menu(ChatContext $context): ChatReply
    {
        $data = $this->index->load();

        return $data === null ? $this->unavailable($context) : $this->menu->run($data, $context);
    }

    /**
     * Whether a rule is active, on the channel and flagged "in menu".
     */
    public function isMenuRule(int $ruleId, ChatChannel $channel): bool
    {
        $data = $this->index->load();

        if ($data === null || ! in_array($ruleId, $data['menu'], true)) {
            return false;
        }

        return MenuSkill::visibleOn($data['rules'][$ruleId], $channel);
    }

    /**
     * Whether a rule is active and on the channel.
     */
    public function isActiveRule(int $ruleId, ChatChannel $channel): bool
    {
        $data = $this->index->load();
        $rule = $data['rules'][$ruleId] ?? null;

        return $rule !== null && MenuSkill::visibleOn($rule, $channel);
    }

    /**
     * @param  array{rules: array<int, array<string, mixed>>, menu: list<int>}  $data
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    private function runRule(array $data, array $rule, ChatContext $context): ChatReply
    {
        $locale = $context->locale;
        $answer = trim((string) ($rule['answer'][$locale] ?? $rule['answer']['uz'] ?? ''));
        $handoff = ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)];

        return match (ChatAction::tryFrom($rule['action'])) {
            ChatAction::Answer => $answer === ''
                ? new ChatReply(__('chatbot.coming_soon', [], $locale), [$handoff], $rule['id'])
                : new ChatReply($answer, $this->ruleButtons($data, $rule, $context), $rule['id']),
            ChatAction::Faq => $this->faq->run($rule, $context),
            ChatAction::CompanyInfo => $this->companyInfo->run($rule, $context),
            ChatAction::Link => $this->link->run($rule, $context),
            ChatAction::Menu => $this->menu->run($data, $context, $rule),
            ChatAction::Handoff => new ChatReply($answer !== '' ? $answer : __('chatbot.handoff', [], $locale), [], $rule['id'], ChatReply::HANDOFF),
            ChatAction::ProductSearch => new ChatReply($answer !== '' ? $answer : __('chatbot.search.prompt', [], $locale), [$handoff], $rule['id'], ChatReply::SEARCH),
            ChatAction::OrderStatus => $this->lookup($context, LookupNumber::ORDER, $rule),
            ChatAction::RepairStatus => $this->lookup($context, LookupNumber::REPAIR, $rule),
            ChatAction::LeadDialog => $this->leadDialog($context, $rule, $answer, $handoff),
            null => new ChatReply(__('chatbot.coming_soon', [], $locale), [$handoff], $rule['id']),
        };
    }

    /**
     * Order / repair status (CB-28): a marker for HandleChatInput on
     * Telegram (with the typed number, if any); on the website only "ask a
     * manager" and the hand-off button, nothing is looked up.
     *
     * @param  LookupNumber::ORDER|LookupNumber::REPAIR  $kind
     * @param  array<string, mixed>|null  $rule  The status rule, or null for a typed number
     */
    private function lookup(ChatContext $context, string $kind, ?array $rule, ?string $number = null): ChatReply
    {
        $locale = $context->locale;
        $handoff = ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)];
        $answer = $rule === null ? '' : trim((string) ($rule['answer'][$locale] ?? $rule['answer']['uz'] ?? ''));

        if ($context->channel === ChatChannel::Web) {
            return new ChatReply($answer !== '' ? $answer : __("chatbot.lookup.web.{$kind}", [], $locale), [$handoff], $rule['id'] ?? null);
        }

        return new ChatReply(
            $answer !== '' ? $answer : __("chatbot.lookup.web.{$kind}", [], $locale),
            [$handoff],
            $rule['id'] ?? null,
            $kind === LookupNumber::ORDER ? ChatReply::ORDER_STATUS : ChatReply::REPAIR_STATUS,
            $number,
        );
    }

    /**
     * A lead dialog rule: on Telegram a marker with the flow (C4); on the
     * website (C5) the rule's answer and the hand-off button, as before.
     *
     * @param  array<string, mixed>  $rule
     * @param  array{type: string, label: string}  $handoff
     */
    private function leadDialog(ChatContext $context, array $rule, string $answer, array $handoff): ChatReply
    {
        $flow = is_string($rule['params']['flow'] ?? null) ? LeadFlow::tryFrom($rule['params']['flow']) : null;
        $text = $answer !== '' ? $answer : __('chatbot.coming_soon', [], $context->locale);

        if ($flow === null || $context->channel === ChatChannel::Web) {
            return new ChatReply($text, [$handoff], $rule['id']);
        }

        return new ChatReply($text, [$handoff], $rule['id'], ChatReply::LEAD, $flow->value);
    }

    /**
     * The extra buttons of an "answer" rule: another rule, a site target or
     * an allow-listed URL, each re-validated here. Up to the button cap.
     *
     * @param  array{rules: array<int, array<string, mixed>>}  $data
     * @param  array<string, mixed>  $rule
     * @return list<array{type: string, label: string, id?: int, url?: string}>
     */
    private function ruleButtons(array $data, array $rule, ChatContext $context): array
    {
        $buttons = [];

        foreach (array_slice((array) $rule['buttons'], 0, (int) config('chatbot.max_buttons', 8)) as $button) {
            if (! is_array($button)) {
                continue;
            }

            $labels = is_array($button['label'] ?? null) ? $button['label'] : [];
            $label = trim((string) ($labels[$context->locale] ?? $labels['uz'] ?? ''));

            if ($label === '') {
                continue;
            }

            $targetId = $button['rule_id'] ?? null;

            if (is_int($targetId)) {
                $target = $data['rules'][$targetId] ?? null;

                if ($target !== null && MenuSkill::visibleOn($target, $context->channel)) {
                    $buttons[] = ['type' => 'rule', 'label' => $label, 'id' => $targetId];
                }

                continue;
            }

            $url = LinkTargets::resolve($button, $context->locale);

            if ($url !== null) {
                $buttons[] = ['type' => 'url', 'label' => $label, 'url' => $url];
            }
        }

        return $buttons;
    }

    /**
     * Cache or build failure: a plain reply, never an exception (CB-2).
     */
    private function unavailable(ChatContext $context): ChatReply
    {
        return new ChatReply(
            __('chatbot.fallback_error', [], $context->locale),
            [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
            null,
            ChatReply::FALLBACK,
        );
    }
}
