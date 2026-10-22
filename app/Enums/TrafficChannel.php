<?php

namespace App\Enums;

/**
 * How a human visitor reached the site (first touch of the session), decided
 * by App\Support\Analytics\TrafficClassifier: UTM tags first, then the
 * referrer host. Internal navigation has no channel of its own.
 */
enum TrafficChannel: string
{
    case Direct = 'direct';
    case Search = 'search';
    case Ai = 'ai';
    case Social = 'social';
    case Referral = 'referral';
    case Campaign = 'campaign';

    public function label(): string
    {
        return __("analytics.enums.traffic_channel.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Ai => 'violet',
            self::Search => 'sky',
            self::Social => 'amber',
            self::Campaign => 'brand',
            self::Referral => 'outline',
            self::Direct => 'slate',
        };
    }
}
