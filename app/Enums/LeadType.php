<?php

namespace App\Enums;

/**
 * What the client asked for.
 */
enum LeadType: string
{
    case Callback = 'callback';
    case Quote = 'quote';
    case ServiceRequest = 'service_request';
    case Checkout = 'checkout';

    public function label(): string
    {
        return __("enums.lead_type.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Callback => 'slate',
            self::Quote => 'brand',
            self::ServiceRequest => 'violet',
            self::Checkout => 'sky',
        };
    }

    /**
     * Service requests go to the service team; every other type is a sales lead
     * (enquiries about service products included: they are quotes, decision 2026-10-22).
     */
    public function isService(): bool
    {
        return $this === self::ServiceRequest;
    }

    public function isSales(): bool
    {
        return ! $this->isService();
    }

    /**
     * Permission a user needs to work with leads of this type (leads.sales / leads.service).
     */
    public function workPermission(): string
    {
        return $this->isService() ? 'leads.service' : 'leads.sales';
    }

    /**
     * @return list<self>
     */
    public static function salesTypes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isSales()));
    }

    /**
     * @return list<string>
     */
    public static function salesValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::salesTypes());
    }
}
