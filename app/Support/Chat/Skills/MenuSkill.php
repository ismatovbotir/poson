<?php

namespace App\Support\Chat\Skills;

use App\Enums\ChatChannel;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;

/**
 * The topic menu: one button per active rule flagged "in menu" for the
 * channel, in menu order. A rule's menu label is params.label (per locale),
 * falling back to its name. Pure: reads the compiled rules only.
 */
final class MenuSkill
{
    /**
     * @param  array{rules: array<int, array<string, mixed>>, menu: list<int>}  $data
     * @return list<array{type: string, label: string, id: int}>
     */
    public function buttons(array $data, ChatContext $context): array
    {
        $buttons = [];

        foreach ($data['menu'] as $id) {
            $rule = $data['rules'][$id] ?? null;

            if ($rule === null || ! self::visibleOn($rule, $context->channel)) {
                continue;
            }

            $labels = is_array($rule['params']['label'] ?? null) ? $rule['params']['label'] : [];
            $label = trim((string) ($labels[$context->locale] ?? $labels['uz'] ?? $rule['name']));

            if ($label !== '') {
                $buttons[] = ['type' => 'rule', 'label' => $label, 'id' => $id];
            }
        }

        return $buttons;
    }

    /**
     * @param  array{rules: array<int, array<string, mixed>>, menu: list<int>}  $data
     * @param  array<string, mixed>|null  $rule  The menu rule itself (its answer is the intro text), if any
     */
    public function run(array $data, ChatContext $context, ?array $rule = null): ChatReply
    {
        $intro = $rule === null ? '' : trim((string) ($rule['answer'][$context->locale] ?? $rule['answer']['uz'] ?? ''));

        return new ChatReply(
            $intro !== '' ? $intro : __('chatbot.menu_title', [], $context->locale),
            $this->buttons($data, $context),
            $rule['id'] ?? null,
            ChatReply::MENU,
        );
    }

    /**
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    public static function visibleOn(array $rule, ChatChannel $channel): bool
    {
        return $channel === ChatChannel::Web ? (bool) $rule['web'] : (bool) $rule['tg'];
    }
}
