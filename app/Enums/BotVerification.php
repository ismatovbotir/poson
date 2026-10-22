<?php

namespace App\Enums;

/**
 * How a crawler's claimed identity (User-Agent) is confirmed by
 * App\Actions\Analytics\VerifyBotHits.
 */
enum BotVerification: string
{
    /** Source IP inside the vendor's published IP ranges (JSON, refreshed weekly). */
    case IpRanges = 'ip_ranges';

    /** Reverse DNS ends with a vendor domain and the forward lookup returns the same IP. */
    case ReverseDns = 'rdns';

    /** The vendor publishes nothing machine-checkable: identified by User-Agent only. */
    case UserAgentOnly = 'ua_only';

    public function label(): string
    {
        return __("analytics.enums.bot_verification.{$this->value}");
    }
}
