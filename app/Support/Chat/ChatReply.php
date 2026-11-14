<?php

namespace App\Support\Chat;

/**
 * What the engine answers: plain text (never markup, CB-5), buttons and the
 * outcome the caller records. Buttons are channel-neutral:
 *   {type: rule, label, id}  - another rule, by id
 *   {type: faq, label, id}   - a FAQ item, by id
 *   {type: url, label, url}  - a validated link
 *   {type: handoff, label}   - talk to a manager
 *   {type: menu, label}      - show the menu
 *   {type: cancel, label}    - leave a dialog
 *   {type: product|order|repair|quote|send|skip, label, id} - a server-stored
 *       choice (Telegram keeps the id in bot_dialog, never in the callback)
 *
 * Outcome markers SEARCH, ORDER_STATUS, REPAIR_STATUS and LEAD mean "the
 * caller must run this" (HandleChatInput, which knows who asks): the engine
 * has no identity and never searches, looks up or creates anything. $param
 * carries the typed order/repair number (lookups) or the lead flow (LEAD).
 *
 * @phpstan-type Button array{type: string, label: string, id?: int, url?: string}
 */
final readonly class ChatReply
{
    public const HIT = 'hit';

    public const MENU = 'menu';

    public const HANDOFF = 'handoff';

    public const UNMATCHED = 'unmatched';

    public const SEARCH = 'search';

    public const COMING_SOON = 'coming_soon';

    public const FALLBACK = 'fallback';

    public const ORDER_STATUS = 'order_status';

    public const REPAIR_STATUS = 'repair_status';

    public const LEAD = 'lead';

    /**
     * The result of a search, lookup or dialog step: its stats are already recorded.
     */
    public const DONE = 'done';

    /**
     * @param  list<array{type: string, label: string, id?: int, url?: string}>  $buttons
     * @param  self::*  $outcome
     */
    public function __construct(
        public string $text,
        public array $buttons = [],
        public ?int $ruleId = null,
        public string $outcome = self::HIT,
        public ?string $param = null,
    ) {}

    public function isHandoff(): bool
    {
        return $this->outcome === self::HANDOFF;
    }

    public function isUnmatched(): bool
    {
        return $this->outcome === self::UNMATCHED;
    }
}
