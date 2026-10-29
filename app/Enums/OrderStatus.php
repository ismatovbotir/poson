<?php

namespace App\Enums;

/**
 * Order status (plan 11 v2 §1.6): new → confirmed → processing → shipped →
 * completed, or cancelled. Forward one step at a time (confirmed may skip
 * processing). Payment never changes the status; the guards that need the
 * payment (cancel, shipped, completed) live in ChangeOrderStatus.
 */
enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("enums.order_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Confirmed => 'violet',
            self::Processing => 'amber',
            self::Shipped => 'brand',
            self::Completed => 'ink',
            self::Cancelled => 'muted',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextStatuses(), true);
    }

    /**
     * Where the order may go from here (cancel included; its payment guard is in ChangeOrderStatus).
     *
     * @return list<self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::New => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Shipped, self::Cancelled],
            self::Processing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    /**
     * The happy path, for the stepper.
     *
     * @return list<self>
     */
    public static function flow(): array
    {
        return [self::New, self::Confirmed, self::Processing, self::Shipped, self::Completed];
    }

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /**
     * Shipped or completed: the goods left the warehouse.
     */
    public function isShippedOrLater(): bool
    {
        return $this === self::Shipped || $this === self::Completed;
    }
}
