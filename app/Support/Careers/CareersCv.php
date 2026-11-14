<?php

namespace App\Support\Careers;

use App\Services\Telegram\TelegramClient;
use App\Support\Secrets;
use App\Support\Telegram\PrivateGroupTarget;

/**
 * Where CVs go (plan 31): the Telegram management chat. Available only with
 * a bot token and a management chat id that is a numeric group id (an
 *
 * @username target is never used, CR-9) and while the careers.cv_enabled
 * switch is on. Without it the CV field is hidden and a sent file is ignored.
 */
final class CareersCv
{
    public const CHAT = 'management';

    public static function isAvailable(): bool
    {
        return CareersSwitch::cvEnabled() && self::isConfigured();
    }

    /**
     * Bot token and a usable management chat id (switch not considered).
     */
    public static function isConfigured(): bool
    {
        return app(TelegramClient::class)->isConfigured() && self::chatId() !== null;
    }

    /**
     * The management chat id when it is a numeric group id, else null.
     */
    public static function chatId(): ?string
    {
        return PrivateGroupTarget::normalize(Secrets::get('telegram.chats.'.self::CHAT));
    }

    /**
     * Whether a management chat value is set at all (even an unusable one).
     */
    public static function chatIsSet(): bool
    {
        return Secrets::isConfigured('telegram.chats.'.self::CHAT);
    }
}
