<?php

namespace App\Enums;

/**
 * The lead dialogs of the chat bot (plan 28 §4, C4): a hard-coded step list
 * per flow, never an editable workflow. Each flow creates one lead of a fixed
 * type (CB-17: the type comes from the server, never from the client).
 */
enum LeadFlow: string
{
    case Callback = 'callback';
    case Quote = 'quote';
    case Service = 'service';

    public function label(): string
    {
        return __("enums.lead_flow.{$this->value}");
    }

    public function leadType(): LeadType
    {
        return match ($this) {
            self::Callback => LeadType::Callback,
            self::Quote => LeadType::Quote,
            self::Service => LeadType::ServiceRequest,
        };
    }

    /**
     * Whether the details step may be skipped (a callback needs no text).
     */
    public function detailsOptional(): bool
    {
        return $this === self::Callback;
    }
}
