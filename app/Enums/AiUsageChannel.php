<?php

namespace App\Enums;

/**
 * Where an AI call is counted (plan 32 §14 plan change 3): the two chat bot
 * channels plus the admin stages (product wizard vision / write, phase B
 * translate). Used only by RecordAiUsage, chat_ai_usage_daily and Stats:
 * ChatChannel stays the two bot channels (its cases are looped by the
 * Console, the settings and the switches). Values fit string(10).
 */
enum AiUsageChannel: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case AdminVision = 'adm_vision';
    case AdminWrite = 'adm_write';
    case AdminTranslate = 'adm_trans';
    // Plan 41: the Virtual office router and role agents (caps in Office → Settings, config/office.php).
    case OfficeRoute = 'vo_route';
    case OfficeSales = 'vo_sales';
    case OfficeService = 'vo_serv';
    case OfficeSupport = 'vo_support';
    // Plan 46 (ED-4, ED46-7): the admin dry-run test console. Only RunEducationDryRun passes it to CallOfficeModel (arch test).
    case OfficeDry = 'vo_dry';

    public function label(): string
    {
        return __("enums.ai_usage_channel.{$this->value}");
    }

    /**
     * The chat bot channels.
     *
     * @return list<self>
     */
    public static function bots(): array
    {
        return [self::Web, self::Telegram];
    }

    public static function fromChatChannel(ChatChannel $channel): self
    {
        return match ($channel) {
            ChatChannel::Web => self::Web,
            ChatChannel::Telegram => self::Telegram,
        };
    }

    public function isAdmin(): bool
    {
        return ! in_array($this, self::bots(), true);
    }

    /**
     * The Virtual office channels (plan 41): their caps come from OfficeSettings, not the chat bot settings.
     *
     * @return list<self>
     */
    public static function office(): array
    {
        return [self::OfficeRoute, self::OfficeSales, self::OfficeService, self::OfficeSupport, self::OfficeDry];
    }

    public function isOffice(): bool
    {
        return in_array($this, self::office(), true);
    }
}
