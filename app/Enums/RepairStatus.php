<?php

namespace App\Enums;

/**
 * Where a repair is (plan 24, owner decision Q1).
 *
 * received → diagnosing → awaiting_approval → in_repair ⇄ awaiting_parts,
 * in_repair → ready → delivered. A warranty repair may go diagnosing →
 * in_repair directly; a ready repair may go back to in_repair (rework).
 * awaiting_approval may go back to diagnosing (the approval is cleared).
 * cancelled is possible from any open status, a ready one too (owner decision,
 * with a note); delivered and cancelled are final.
 */
enum RepairStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case InRepair = 'in_repair';
    case AwaitingParts = 'awaiting_parts';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __("enums.repair_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Received => 'sky',
            self::Diagnosing => 'violet',
            self::AwaitingApproval => 'amber',
            self::InRepair => 'brand',
            self::AwaitingParts => 'amber',
            self::Ready => 'brand',
            self::Delivered => 'ink',
            self::Cancelled => 'muted',
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function isClosed(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /**
     * Statuses reachable from this one, before the coverage rule (see canTransitionTo).
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Received => [self::Diagnosing, self::Cancelled],
            self::Diagnosing => [self::AwaitingApproval, self::InRepair, self::Cancelled],
            self::AwaitingApproval => [self::Diagnosing, self::InRepair, self::Cancelled],
            self::InRepair => [self::AwaitingParts, self::Ready, self::Cancelled],
            self::AwaitingParts => [self::InRepair, self::Cancelled],
            self::Ready => [self::Delivered, self::InRepair, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    /**
     * The move is on the flow. diagnosing → in_repair skips the estimate, so
     * only a warranty repair may take it.
     */
    public function canTransitionTo(self $to, RepairCoverage $coverage): bool
    {
        if (! in_array($to, $this->next(), true)) {
            return false;
        }

        return ! ($this === self::Diagnosing && $to === self::InRepair && $coverage !== RepairCoverage::Warranty);
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
