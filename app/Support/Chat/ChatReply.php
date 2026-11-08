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

    /**
     * @param  list<array{type: string, label: string, id?: int, url?: string}>  $buttons
     * @param  self::*  $outcome
     */
    public function __construct(
        public string $text,
        public array $buttons = [],
        public ?int $ruleId = null,
        public string $outcome = self::HIT,
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
