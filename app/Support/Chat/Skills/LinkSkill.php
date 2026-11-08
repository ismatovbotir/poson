<?php

namespace App\Support\Chat\Skills;

use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\LinkTargets;

/**
 * A "link" rule: its answer text plus one link button to a site page or an
 * allow-listed external URL (CB-6). The target is resolved and validated
 * again here, at render time; a target that is no longer valid gives the
 * "ask a manager" reply instead of a broken or unsafe link.
 */
final class LinkSkill
{
    /**
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    public function run(array $rule, ChatContext $context): ChatReply
    {
        $url = LinkTargets::resolve($rule['params'], $context->locale);

        if ($url === null) {
            return new ChatReply(
                __('chatbot.coming_soon', [], $context->locale),
                [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
                $rule['id'],
            );
        }

        $labels = is_array($rule['params']['link_label'] ?? null) ? $rule['params']['link_label'] : [];
        $label = trim((string) ($labels[$context->locale] ?? $labels['uz'] ?? $rule['name']));
        $text = trim((string) ($rule['answer'][$context->locale] ?? $rule['answer']['uz'] ?? ''));

        return new ChatReply($text !== '' ? $text : $label, [['type' => 'url', 'label' => $label, 'url' => $url]], $rule['id']);
    }
}
