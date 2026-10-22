<?php

namespace App\Enums;

/**
 * Device class of a human visit, guessed from the User-Agent.
 */
enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';

    public function label(): string
    {
        return __("analytics.enums.device_type.{$this->value}");
    }

    public static function fromUserAgent(?string $userAgent): self
    {
        $userAgent = (string) $userAgent;

        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $userAgent) === 1) {
            return self::Tablet;
        }

        if (preg_match('/Mobi|iPhone|iPod|Android|Opera Mini|IEMobile/i', $userAgent) === 1) {
            return self::Mobile;
        }

        return self::Desktop;
    }
}
