<?php

namespace App\Support\Chat\Skills;

use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Catalog\CategoryTree;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;

/**
 * FAQ answers for the chat (plan 28, CB-7). Only active items of a public
 * owner: the general FAQ, a visible category, a visible product or an active
 * page. The owner is checked again every time an answer is built, so an item
 * of a category hidden after the rule was saved is never sent. Brand-unsafe
 * text is skipped like on the site (FaqContent).
 */
final class FaqSkill
{
    /**
     * The general FAQ as a list of question buttons (or the one item named in
     * the rule params).
     *
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    public function run(array $rule, ChatContext $context): ChatReply
    {
        $itemId = $rule['params']['faq_id'] ?? null;

        if (is_int($itemId)) {
            $item = $this->item($itemId, $context->locale);

            return $item === null
                ? new ChatReply(__('chatbot.faq.unavailable', [], $context->locale), [$this->handoffButton($context)], $rule['id'])
                : new ChatReply($item['answer'], [], $rule['id']);
        }

        $buttons = [];
        $limit = min(10, max(1, (int) config('chatbot.telegram.faq_items', 6)));

        $items = FaqItem::query()->active()->ownedBy(null)->ordered()->limit($limit * 2)->get();

        foreach ($items as $item) {
            $question = $this->localized($item, 'question', $context->locale);

            if ($question === '' || BrandSafeText::violates($question) || $this->answerOf($item, $context->locale) === '') {
                continue;
            }

            $buttons[] = ['type' => 'faq', 'label' => $this->shorten($question), 'id' => $item->id];

            if (count($buttons) >= $limit) {
                break;
            }
        }

        if ($buttons === []) {
            return new ChatReply(__('chatbot.faq.empty', [], $context->locale), [$this->handoffButton($context)], $rule['id']);
        }

        $intro = trim((string) ($rule['answer'][$context->locale] ?? $rule['answer']['uz'] ?? ''));

        return new ChatReply($intro !== '' ? $intro : __('chatbot.faq.pick', [], $context->locale), $buttons, $rule['id']);
    }

    /**
     * One FAQ item's question and answer, or null when it is not public now.
     *
     * @return array{question: string, answer: string}|null
     */
    public function item(int $id, string $locale): ?array
    {
        $item = FaqItem::query()->active()->with('faqable')->find($id);

        if (! $item instanceof FaqItem || ! $this->isPublicOwner($item)) {
            return null;
        }

        $question = $this->localized($item, 'question', $locale);
        $answer = $this->answerOf($item, $locale);

        return $question === '' || $answer === '' ? null : ['question' => $question, 'answer' => $answer];
    }

    private function isPublicOwner(FaqItem $item): bool
    {
        if ($item->isGeneral()) {
            return true;
        }

        $owner = $item->faqable;

        return match (true) {
            $owner instanceof Category => $owner->is_active && CategoryTree::isVisible($owner->id),
            $owner instanceof Product => $owner->isVisible(),
            $owner instanceof Page => $owner->is_active,
            default => false,
        };
    }

    private function answerOf(FaqItem $item, string $locale): string
    {
        $answer = $this->localized($item, 'answer', $locale);

        return $answer === '' || BrandSafeText::violates($answer) ? '' : $answer;
    }

    private function localized(FaqItem $item, string $field, string $locale): string
    {
        $values = $item->getTranslations($field);

        return trim((string) ($values[$locale] ?? $values['uz'] ?? ''));
    }

    private function shorten(string $text): string
    {
        $max = (int) config('chatbot.label_max', 40);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * @return array{type: string, label: string}
     */
    private function handoffButton(ChatContext $context): array
    {
        return ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)];
    }
}
