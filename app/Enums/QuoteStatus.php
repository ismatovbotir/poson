<?php

namespace App\Enums;

/**
 * Quote status (plan 06). draft → sent (link copied, PDF downloaded or by
 * hand) → accepted | rejected | expired. A draft may also be closed
 * directly. Closed quotes are final; duplicate one to offer again.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return __("enums.quote_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Sent => 'sky',
            self::Accepted => 'brand',
            self::Rejected => 'muted',
            self::Expired => 'amber',
        };
    }

    /**
     * Draft and sent quotes can still be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Draft => in_array($to, [self::Sent, self::Accepted, self::Rejected, self::Expired], true),
            self::Sent => in_array($to, [self::Accepted, self::Rejected, self::Expired], true),
            default => false,
        };
    }

    /**
     * Statuses set from the quote page (sent has its own action).
     *
     * @return list<self>
     */
    public static function closing(): array
    {
        return [self::Accepted, self::Rejected, self::Expired];
    }
}
