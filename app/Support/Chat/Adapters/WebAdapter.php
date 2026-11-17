<?php

namespace App\Support\Chat\Adapters;

use App\Support\Chat\ChatReply;

/**
 * Turns a ChatReply into a stored website chat bot message (plan 28 C5
 * §19.3, CB-5, CB-32, W-8, WC-7, WC-16). Pure: no database, no HTTP.
 *
 *  - Text stays plain (the widget escapes it on output), cut to TEXT_MAX.
 *  - Buttons are stored server-side on the message as {type, label?, id?|url?}
 *    and pressed by index into the LATEST bot message only. Product buttons
 *    keep the id only (the card, name and Retail price are built live at
 *    render), at most chatbot.web.product_buttons of them; quote, rule and
 *    faq buttons keep their id; url buttons their URL (re-validated through
 *    LinkTargets when rendered, never output as stored); handoff, menu and
 *    callback (after-hours "request a callback") carry no target.
 *  - At most MAX_BUTTONS buttons; unknown types and empty labels are dropped.
 */
final class WebAdapter
{
    public const TEXT_MAX = 2000;

    public const LABEL_MAX = 60;

    public const MAX_BUTTONS = 10;

    public const ID_TYPES = ['rule', 'faq', 'product', 'quote'];

    public const PLAIN_TYPES = ['handoff', 'menu', 'callback'];

    /**
     * @return array{text: string, buttons: list<array{type: string, label?: string, id?: int, url?: string}>}
     */
    public function render(ChatReply $reply): array
    {
        $buttons = [];
        $products = 0;
        $productMax = max(0, (int) config('chatbot.web.product_buttons', 8));

        foreach ($reply->buttons as $button) {
            if (count($buttons) >= self::MAX_BUTTONS) {
                break;
            }

            $type = (string) ($button['type'] ?? '');
            $label = mb_substr(trim((string) ($button['label'] ?? '')), 0, self::LABEL_MAX);

            if ($type === 'product') {
                if ($products >= $productMax || ! is_int($button['id'] ?? null)) {
                    continue;
                }

                $products++;
                $buttons[] = ['type' => 'product', 'id' => $button['id']];

                continue;
            }

            if ($label === '') {
                continue;
            }

            if (in_array($type, self::ID_TYPES, true) && is_int($button['id'] ?? null)) {
                $buttons[] = ['type' => $type, 'label' => $label, 'id' => $button['id']];
            } elseif ($type === 'url' && is_string($button['url'] ?? null) && $button['url'] !== '') {
                $buttons[] = ['type' => 'url', 'label' => $label, 'url' => $button['url']];
            } elseif (in_array($type, self::PLAIN_TYPES, true)) {
                $buttons[] = ['type' => $type, 'label' => $label];
            }
        }

        return [
            'text' => mb_substr(trim($reply->text), 0, self::TEXT_MAX),
            'buttons' => $buttons,
        ];
    }
}
