<?php

namespace App\Enums;

/**
 * Part of the settings a change belongs to (settings_audits.area): the tabs
 * of Settings → Company, plus Integrations (plan 04b).
 */
enum SettingsArea: string
{
    case Contacts = 'contacts';
    case Legal = 'legal';
    case Social = 'social';
    case Hero = 'hero';
    case Seo = 'seo';
    case Tracking = 'tracking';
    case Integrations = 'integrations';

    public function label(): string
    {
        return __("enums.settings_area.{$this->value}");
    }

    /**
     * Value of the settings.group column for keys of this area.
     */
    public function group(): string
    {
        return match ($this) {
            self::Contacts, self::Legal, self::Social, self::Hero => 'company',
            self::Seo, self::Tracking => 'seo',
            self::Integrations => 'integrations',
        };
    }

    /**
     * Areas that also need the settings.integrations permission (plan 04 §6).
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::Legal, self::Tracking, self::Integrations], true);
    }
}
