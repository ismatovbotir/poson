<?php

namespace App\Enums;

/**
 * Part of the settings a change belongs to (settings_audits.area): the tabs
 * of Settings → Company, plus Integrations (plan 04b). AiBots holds the
 * per-crawler robots.txt policy (key robots.ai_bots); Faq is the general FAQ
 * tab (faq_items rows, not settings keys) and is used for access only.
 * ApiTokens (token created / revoked) and PriceSync (a product unlinked
 * from the price API) are audit-only areas of plan 09. Audit rows may hold
 * legacy values no longer listed here (e.g. "ai_prompts"): read them with
 * tryFrom() (SettingsAudit does).
 */
enum SettingsArea: string
{
    case Contacts = 'contacts';
    case Legal = 'legal';
    case Social = 'social';
    case Hero = 'hero';
    case Seo = 'seo';
    case Tracking = 'tracking';
    case AiBots = 'ai_bots';
    case Faq = 'faq';
    case Integrations = 'integrations';
    case ApiTokens = 'api_tokens';
    case PriceSync = 'price_sync';

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
            self::Contacts, self::Legal, self::Social, self::Hero, self::Faq => 'company',
            self::Seo, self::Tracking, self::AiBots => 'seo',
            self::Integrations, self::ApiTokens, self::PriceSync => 'integrations',
        };
    }

    /**
     * Areas that also need the settings.integrations permission (plan 04 §6).
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::Legal, self::Tracking, self::Integrations, self::ApiTokens, self::PriceSync], true);
    }
}
