<?php

namespace App\Enums;

/**
 * Pipeline status of a lead (Murojaat), plan 05 §Enums.
 *
 * Open: new → in_progress → qualified (any open status may move to any
 * other status). Closed: converted, lost. A closed lead can only be
 * reopened, which puts it back to in_progress (ChangeLeadStatus logs it).
 */
enum LeadStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Lost = 'lost';

    public function label(): string
    {
        return __("enums.lead_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::InProgress => 'amber',
            self::Qualified => 'violet',
            self::Converted => 'brand',
            self::Lost => 'muted',
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function isClosed(): bool
    {
        return $this === self::Converted || $this === self::Lost;
    }

    /**
     * Allowed moves: open → any other status; closed → in_progress (reopen) only.
     */
    public function canTransitionTo(self $to): bool
    {
        if ($to === $this) {
            return false;
        }

        return $this->isOpen() || $to === self::InProgress;
    }

    /**
     * Whether staff may set this status by hand. Converted is set only by the
     * conversion action (plan 05b), never from the status control.
     */
    public function isManual(): bool
    {
        return $this !== self::Converted;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isOpen()));
    }

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isClosed()));
    }

    /**
     * @param  list<self>  $cases
     * @return list<string>
     */
    public static function valuesOf(array $cases): array
    {
        return array_map(fn (self $status): string => $status->value, $cases);
    }
}
