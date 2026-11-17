<?php

namespace App\Support\Chat;

use App\Enums\LeadFlow;

/**
 * The validated web_chats.dialog (plan 28 C5 §19.3, CB-17, WC-8). Anything
 * else is dropped; at most 2 KB:
 *
 *  - form: the inline lead form the server opened, {flow (LeadFlow),
 *    product_id (int|null, quote only), at (unix time the card opened, the
 *    widget's honeypot "rendered at")}. Flow, type, source and product come
 *    only from here, never from the client.
 *  - ai: reserved for Plan 33 B (the waiting AI turn id); any new answer drops it.
 */
final class WebChatDialog
{
    public const MAX_BYTES = 2048;

    /**
     * @return array{form?: array{flow: string, product_id: int|null, at: int}, ai?: int}
     */
    public static function clean(mixed $dialog): array
    {
        if (! is_array($dialog)) {
            return [];
        }

        $clean = [];
        $form = $dialog['form'] ?? null;

        if (is_array($form) && is_string($form['flow'] ?? null) && LeadFlow::tryFrom($form['flow']) !== null && is_int($form['at'] ?? null)) {
            $productId = $form['product_id'] ?? null;
            $clean['form'] = [
                'flow' => $form['flow'],
                'product_id' => $form['flow'] === LeadFlow::Quote->value && is_int($productId) && $productId > 0 ? $productId : null,
                'at' => $form['at'],
            ];
        }

        if (is_int($dialog['ai'] ?? null) && $dialog['ai'] > 0) {
            $clean['ai'] = $dialog['ai'];
        }

        return strlen((string) json_encode($clean)) > self::MAX_BYTES ? [] : $clean;
    }

    /**
     * @return array{flow: LeadFlow, product_id: int|null, at: int}|null
     */
    public static function form(mixed $dialog): ?array
    {
        $form = self::clean($dialog)['form'] ?? null;

        return $form === null ? null : ['flow' => LeadFlow::from($form['flow']), 'product_id' => $form['product_id'], 'at' => $form['at']];
    }

    /**
     * @return array{form: array{flow: string, product_id: int|null, at: int}}
     */
    public static function withForm(LeadFlow $flow, ?int $productId = null): array
    {
        return ['form' => ['flow' => $flow->value, 'product_id' => $flow === LeadFlow::Quote ? $productId : null, 'at' => now()->getTimestamp()]];
    }
}
