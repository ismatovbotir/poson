<?php

namespace App\Support\Repairs;

use App\Enums\RepairStatus;
use App\Models\Lead;
use App\Models\Repair;
use App\Support\DateFormat;
use App\Support\Telegram\Bot\BotText;

/**
 * Everything the public repair status page /r/{token} may show (plan 35 §5,
 * P35-12). The view receives only this object, never the model, so a template
 * mistake cannot reach the contact, serial, problem, diagnosis or amounts.
 */
final readonly class RepairPublicStatus
{
    public const DEVICE_MAX = 60;

    /**
     * Columns the page reads: nothing else of a repair is ever loaded.
     */
    public const COLUMNS = ['id', 'number', 'status', 'status_changed_at', 'received_at', 'closed_at', 'device_type', 'device_brand', 'device_model', 'locale'];

    /**
     * Number of steps in the progress bar (Received, Diagnosing, In repair, Ready, Delivered).
     */
    public const STEPS = 5;

    public function __construct(
        public string $number,
        public string $statusKey,
        public string $statusLabel,
        public ?string $statusDay,
        public ?string $receivedDay,
        public ?string $closedDay,
        public string $deviceLine,
        public string $messageKey,
        public bool $isReady,
        public bool $isClosed,
        public ?int $step,
    ) {}

    public static function fromRepair(Repair $repair, string $locale): self
    {
        $status = $repair->status;
        $day = fn ($date): ?string => DateFormat::iso($date?->copy()->setTimezone(Lead::TIMEZONE), 'D MMMM YYYY', $locale);

        return new self(
            number: (string) $repair->number,
            statusKey: $status->value,
            statusLabel: __('enums.repair_status.'.$status->value, [], $locale),
            statusDay: $day($repair->status_changed_at),
            receivedDay: $day($repair->received_at),
            closedDay: $day($repair->closed_at ?? ($status === RepairStatus::Delivered ? $repair->status_changed_at : null)),
            deviceLine: self::deviceLine($repair, $locale),
            messageKey: 'repairs.public.message.'.match ($status) {
                RepairStatus::Received, RepairStatus::Diagnosing => 'diagnostics',
                default => $status->value,
            },
            isReady: $status === RepairStatus::Ready,
            isClosed: $status->isClosed(),
            step: self::step($status),
        );
    }

    /**
     * "Scanner · Zebra DS2208": plain text (the view escapes it), control and format
     * characters removed, phone-like digit groups and any run of 7 or more digits removed (a phone
     * number typed into the model field), at most DEVICE_MAX characters.
     */
    public static function deviceLine(Repair $repair, string $locale): string
    {
        $free = trim(BotText::clean($repair->device_brand).' '.BotText::clean($repair->device_model));
        $free = (string) preg_replace('/(?<![\p{L}\p{N}])\+?\d(?:[\s.\-()]*\d){8,}/u', ' ', $free);
        $free = (string) preg_replace('/\d{7,}/u', ' ', $free);
        $free = trim((string) preg_replace('/\s+/u', ' ', $free));

        $line = implode(' · ', array_filter([
            __('enums.repair_device_type.'.$repair->device_type->value, [], $locale),
            $free,
        ], fn (string $part): bool => $part !== ''));

        return mb_strlen($line) > self::DEVICE_MAX ? rtrim(mb_substr($line, 0, self::DEVICE_MAX - 1)).'…' : $line;
    }

    /**
     * Zero-based step of the progress bar; null for a cancelled repair (no bar).
     */
    private static function step(RepairStatus $status): ?int
    {
        return match ($status) {
            RepairStatus::Received => 0,
            RepairStatus::Diagnosing, RepairStatus::AwaitingApproval => 1,
            RepairStatus::InRepair, RepairStatus::AwaitingParts => 2,
            RepairStatus::Ready => 3,
            RepairStatus::Delivered => 4,
            RepairStatus::Cancelled => null,
        };
    }
}
