<?php

namespace App\Enums;

/**
 * Entry of a CRM timeline (activities table), written by the actions.
 * Staff add notes and calls by hand; the others are system entries.
 */
enum ActivityType: string
{
    case Created = 'created';
    case Note = 'note';
    case Call = 'call';
    case StatusChange = 'status_change';
    case Assignment = 'assignment';
    case Notification = 'notification';
    case Conversion = 'conversion';
    case PriceLevelChange = 'price_level_change';
    case Quote = 'quote';
    case Order = 'order';
    case Payment = 'payment';

    public function label(): string
    {
        return __("enums.activity_type.{$this->value}");
    }

    /**
     * Icon from the admin sprite.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Created => 'inbox',
            self::Note => 'edit',
            self::Call => 'phone',
            self::StatusChange => 'check',
            self::Assignment => 'users',
            self::Notification => 'telegram',
            self::Conversion => 'building',
            self::PriceLevelChange => 'tag',
            self::Quote => 'file-text',
            self::Order => 'bag',
            self::Payment => 'card',
        };
    }

    /**
     * Timeline dot color (Tailwind background class).
     */
    public function dot(): string
    {
        return match ($this) {
            self::Created => 'bg-sky-500',
            self::Note => 'bg-slate-400',
            self::Call => 'bg-brand-600',
            self::StatusChange => 'bg-violet-500',
            self::Assignment => 'bg-amber-400',
            self::Notification => 'bg-slate-300',
            self::Conversion => 'bg-brand-600',
            self::PriceLevelChange => 'bg-sky-500',
            self::Quote => 'bg-violet-500',
            self::Order => 'bg-slate-900',
            self::Payment => 'bg-brand-600',
        };
    }

    /**
     * Types staff add by hand (AddLeadActivity).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Note, self::Call];
    }
}
