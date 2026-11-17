<?php

namespace App\Support\Chat;

use App\Enums\ChatChannel;

/**
 * Who a chat bot answer is for (plan 28 C5 §19.1, WC-1, WC-2): a Telegram
 * customer (TelegramUser) or a website chat (WebChat). Only the product
 * search (AnswerProductSearch) and the unmatched list (RecordUnmatched) take
 * it; the lead dialog, lookups and AI stay Telegram-typed.
 */
interface ChatSubject
{
    /**
     * The channel for stats, the chat_unmatched row and the channel's own
     * global limiter budgets (never hard-coded by the caller).
     */
    public function chatChannel(): ChatChannel;

    /**
     * The per-subject part of a limiter key. Telegram returns the bare id,
     * so its keys stay byte-identical to before C5; the web returns
     * "web:{id}", so ids of the two tables never share a budget.
     */
    public function chatLimiterKey(): string;

    /**
     * Whether a phrase this subject sent may go to the unmatched list.
     */
    public function mayRecordPhrases(): bool;
}
