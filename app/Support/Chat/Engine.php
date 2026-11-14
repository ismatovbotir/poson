<?php

namespace App\Support\Chat;

use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Enums\LeadFlow;
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
 */
class Engine
{
    public function __construct(
        private readonly RuleIndex $index,
        private readonly FaqSkill $faq,
        private readonly CompanyInfoSkill $companyInfo,
        private readonly LinkSkill $link,
        private readonly MenuSkill $menu,
    ) {}

    public function respond(ChatContext $context): ChatReply
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
            return $this->runRule($data, $data['rules'][$match->ruleId], $context);
        }

        if (ChatText::looksLikeModel($normalized)) {
            return new ChatReply(
                __('chatbot.search.prompt', [], $context->locale),
                [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
                null,
                ChatReply::SEARCH,
            );
        }

        return new ChatReply(
            ChatSettings::text('fallback', $context->locale) ?? __('chatbot.fallback', [], $context->locale),
            [...$this->menu->buttons($data, $context), ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
            null,
            $normalized === '' ? ChatReply::FALLBACK : ChatReply::UNMATCHED,
        );
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
